<?php

namespace Tests\Feature;

use App\Services\BackupService;
use App\Support\CompanyApiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    private string $companyId;

    private string $otherCompanyId;

    private string $adminId;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->createCompany('Target Co');
        $this->otherCompanyId = $this->createCompany('Other Co');
        $this->adminId = $this->createUser('owner', 'Admin', $this->companyId);
        $this->adminToken = $this->issueToken($this->adminId, 'owner', 'Admin', $this->companyId);
    }

    public function test_public_vendor_signup_cannot_attach_itself_to_a_company(): void
    {
        $this->postJson('/api/db/query', [
            'action' => 'insert',
            'table' => 'vendors',
            'payload' => [[
                'vendor_name' => 'Evil Vendor',
                'category' => 'Photographer',
                'username' => 'evilvendor',
                'password' => 'longpassword1',
                'company_id' => $this->companyId,
                'status' => 'Approved',
            ]],
        ], ['X-Company-Id' => $this->companyId])->assertOk();

        $vendor = DB::table('vendors')->where('username', 'evilvendor')->first();
        $this->assertNull($vendor->company_id);
        $this->assertSame('Active', $vendor->status);
        $this->assertTrue(Hash::check('longpassword1', $vendor->password));
    }

    public function test_public_vendor_signup_cannot_reuse_a_staff_username(): void
    {
        $this->postJson('/api/db/query', [
            'action' => 'insert',
            'table' => 'vendors',
            'payload' => [['vendor_name' => 'Clone', 'category' => 'DJ', 'username' => 'owner', 'password' => 'longpassword1']],
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('vendors')->where('username', 'owner')->count());
    }

    public function test_vendor_session_cannot_read_users_or_take_over_the_admin_account(): void
    {
        $vendorToken = $this->vendorLogin($this->createVendor('photo', $this->companyId));
        $adminHash = DB::table('users')->where('id', $this->adminId)->value('password');

        $this->dbQuery($vendorToken, ['action' => 'select', 'table' => 'users'])->assertForbidden();
        $this->dbQuery($vendorToken, ['action' => 'select', 'table' => 'payments'])->assertForbidden();
        $this->dbQuery($vendorToken, [
            'action' => 'update',
            'table' => 'users',
            'payload' => ['password' => 'pwned12345'],
            'filters' => [['column' => 'id', 'op' => 'eq', 'value' => $this->adminId]],
        ])->assertForbidden();

        $this->assertSame($adminHash, DB::table('users')->where('id', $this->adminId)->value('password'));
        $this->postJson('/api/auth/login', ['username' => 'owner', 'password' => 'pwned12345'])->assertUnauthorized();
    }

    public function test_vendor_session_only_sees_and_edits_its_own_profile(): void
    {
        $ownId = $this->createVendor('photo', $this->companyId);
        $otherId = $this->createVendor('caterer', $this->companyId);
        $vendorToken = $this->vendorLogin($ownId);

        $rows = $this->dbQuery($vendorToken, ['action' => 'select', 'table' => 'vendors'])->assertOk()->json('data');
        $this->assertSame([$ownId], array_column($rows, 'id'));

        $this->dbQuery($vendorToken, [
            'action' => 'update',
            'table' => 'vendors',
            'payload' => ['vendor_name' => 'Hijacked', 'status' => 'Inactive'],
            'filters' => [['column' => 'id', 'op' => 'eq', 'value' => $otherId]],
        ])->assertOk();
        $this->assertSame('caterer shop', DB::table('vendors')->where('id', $otherId)->value('vendor_name'));

        $this->dbQuery($vendorToken, [
            'action' => 'update',
            'table' => 'vendors',
            'payload' => ['vendor_name' => 'Photo Studio', 'status' => 'Inactive'],
            'filters' => [['column' => 'id', 'op' => 'eq', 'value' => $ownId]],
        ])->assertOk();
        $own = DB::table('vendors')->where('id', $ownId)->first();
        $this->assertSame('Photo Studio', $own->vendor_name);
        $this->assertSame('Active', $own->status);

        $bootstrap = $this->postJson('/api/db/bootstrap', [], ['X-Api-Token' => $vendorToken])->assertOk();
        $this->assertSame([$ownId], array_column($bootstrap->json('data.vendors'), 'id'));
        $this->assertSame([], $bootstrap->json('data.users'));
        $this->assertSame([], $bootstrap->json('data.payments'));
    }

    public function test_inactive_vendor_cannot_log_in(): void
    {
        $vendorId = $this->createVendor('blocked', $this->companyId);
        DB::table('vendors')->where('id', $vendorId)->update(['status' => 'Inactive']);

        $this->postJson('/api/auth/login', ['username' => 'blocked', 'password' => 'secret123'])->assertForbidden();
    }

    public function test_manager_cannot_manage_other_accounts(): void
    {
        $managerId = $this->createUser('manager', 'Manager', $this->companyId);
        $managerToken = $this->issueToken($managerId, 'manager', 'Manager', $this->companyId);
        $adminHash = DB::table('users')->where('id', $this->adminId)->value('password');

        $this->dbQuery($managerToken, [
            'action' => 'update',
            'table' => 'users',
            'payload' => ['password' => 'pwned12345'],
            'filters' => [['column' => 'id', 'op' => 'eq', 'value' => $this->adminId]],
        ])->assertOk();
        $this->assertSame($adminHash, DB::table('users')->where('id', $this->adminId)->value('password'));

        $this->dbQuery($managerToken, [
            'action' => 'insert',
            'table' => 'users',
            'payload' => ['username' => 'sneaky', 'password' => 'whatever12'],
        ])->assertForbidden();
        $this->dbQuery($managerToken, [
            'action' => 'delete',
            'table' => 'users',
            'filters' => [['column' => 'id', 'op' => 'eq', 'value' => $this->adminId]],
        ])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $this->adminId]);
    }

    public function test_module_access_is_enforced_on_the_server(): void
    {
        $managerId = $this->createUser('fnmanager', 'Manager', $this->companyId, ['functions']);
        $managerToken = $this->issueToken($managerId, 'fnmanager', 'Manager', $this->companyId);

        $this->dbQuery($managerToken, ['action' => 'insert', 'table' => 'employees', 'payload' => ['name' => 'Ghost']])->assertForbidden();
        $this->dbQuery($managerToken, ['action' => 'insert', 'table' => 'halls', 'payload' => ['name' => 'Ghost Hall']])->assertForbidden();
        $this->dbQuery($managerToken, ['action' => 'post_journal_voucher', 'payload' => ['header' => [], 'lines' => []]])->assertForbidden();
        $this->assertSame(0, DB::table('employees')->where('name', 'Ghost')->count());

        $this->dbQuery($managerToken, ['action' => 'insert', 'table' => 'menu_categories', 'payload' => ['name' => 'Desserts']])->assertOk();
    }

    public function test_roles_without_app_access_cannot_read_company_data(): void
    {
        $staffId = $this->createUser('cleaner', 'Staff', $this->companyId);
        $staffToken = $this->issueToken($staffId, 'cleaner', 'Staff', $this->companyId);

        $this->dbQuery($staffToken, ['action' => 'select', 'table' => 'employees'])->assertForbidden();
        $this->postJson('/api/db/bootstrap', [], ['X-Api-Token' => $staffToken])->assertForbidden();
    }

    public function test_backups_are_admin_only(): void
    {
        $managerId = $this->createUser('manager', 'Manager', $this->companyId);
        $managerToken = $this->issueToken($managerId, 'manager', 'Manager', $this->companyId);

        $this->getJson('/api/backup/download', ['X-Api-Token' => $managerToken])->assertForbidden();
        $this->postJson('/api/backup/run', [], ['X-Api-Token' => $managerToken])->assertForbidden();
        $this->postJson('/api/backup/settings', ['path' => 'C:/evil'], ['X-Api-Token' => $managerToken])->assertForbidden();
    }

    public function test_backup_path_cannot_be_changed_through_the_api(): void
    {
        $this->postJson('/api/backup/settings', ['path' => 'C:/evil', 'auto_enabled' => false, 'retention_days' => 5], ['X-Api-Token' => $this->adminToken])
            ->assertOk();

        $this->assertSame(0, DB::table('system_settings')->where('key', 'backup_path')->count());
        $service = app(BackupService::class);
        $this->assertFalse($service->autoEnabled($this->companyId));
        $this->assertTrue($service->autoEnabled($this->otherCompanyId));
        $this->assertSame(5, $service->retentionDays($this->companyId));
    }

    public function test_backups_do_not_contain_password_hashes_and_restore_keeps_passwords(): void
    {
        $dir = storage_path('framework/testing/backups_'.Str::random(8));
        config(['backup.path' => $dir]);
        $this->createVendor('photo', $this->companyId);

        try {
            $service = app(BackupService::class);
            $backup = $service->createBackup('test', $this->companyId);

            $json = $this->readBackupJson($backup['path']);
            $this->assertStringNotContainsString('$2y$', $json);
            $this->assertStringContainsString('owner', $json);

            $service->restoreFromFile($backup['path'], $backup['filename'], $this->companyId);
            $this->postJson('/api/auth/login', ['username' => 'owner', 'password' => 'secret123'])->assertOk();
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_system_settings_are_isolated_per_company(): void
    {
        $otherAdmin = $this->createUser('rival', 'Admin', $this->otherCompanyId);
        $otherToken = $this->issueToken($otherAdmin, 'rival', 'Admin', $this->otherCompanyId);
        DB::table('system_settings')->insert(['key' => 'advance_agreement_terms', 'value' => 'Default terms', 'company_id' => null, 'created_at' => now(), 'updated_at' => now()]);

        $this->dbQuery($otherToken, [
            'action' => 'upsert',
            'table' => 'system_settings',
            'payload' => [['key' => 'advance_agreement_terms', 'value' => 'Rival terms']],
            'onConflict' => 'key',
        ])->assertOk();

        $mine = $this->dbQuery($this->adminToken, [
            'action' => 'select',
            'table' => 'system_settings',
            'filters' => [['column' => 'key', 'op' => 'eq', 'value' => 'advance_agreement_terms']],
            'maybeSingle' => true,
        ])->assertOk();
        $this->assertSame('Default terms', $mine->json('data.value'));

        $theirs = $this->dbQuery($otherToken, [
            'action' => 'select',
            'table' => 'system_settings',
            'filters' => [['column' => 'key', 'op' => 'eq', 'value' => 'advance_agreement_terms']],
            'maybeSingle' => true,
        ])->assertOk();
        $this->assertSame('Rival terms', $theirs->json('data.value'));
    }

    public function test_upsert_cannot_overwrite_another_companys_row(): void
    {
        $hallId = (string) Str::uuid();
        DB::table('halls')->insert(['id' => $hallId, 'name' => 'Rival Hall', 'company_id' => $this->otherCompanyId, 'created_at' => now(), 'updated_at' => now()]);

        $this->dbQuery($this->adminToken, [
            'action' => 'upsert',
            'table' => 'halls',
            'payload' => [['id' => $hallId, 'name' => 'Stolen Hall']],
            'onConflict' => 'id',
        ]);

        $hall = DB::table('halls')->where('id', $hallId)->first();
        $this->assertSame('Rival Hall', $hall->name);
        $this->assertSame($this->otherCompanyId, $hall->company_id);
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->postJson('/api/auth/login', ['username' => 'owner', 'password' => 'secret123'])->assertOk()->json('data.api_token');

        $this->dbQuery($token, ['action' => 'select', 'table' => 'halls'])->assertOk();
        $this->postJson('/api/auth/logout', [], ['X-Api-Token' => $token])->assertOk();
        $this->dbQuery($token, ['action' => 'select', 'table' => 'halls'])->assertUnauthorized();
    }

    public function test_password_change_and_deletion_revoke_existing_sessions(): void
    {
        $managerId = $this->createUser('manager', 'Manager', $this->companyId);
        $managerToken = $this->postJson('/api/auth/login', ['username' => 'manager', 'password' => 'secret123'])->json('data.api_token');

        $this->dbQuery($this->adminToken, [
            'action' => 'update',
            'table' => 'users',
            'payload' => ['password' => 'newpassword1'],
            'filters' => [['column' => 'id', 'op' => 'eq', 'value' => $managerId]],
        ])->assertOk();
        $this->dbQuery($managerToken, ['action' => 'select', 'table' => 'halls'])->assertUnauthorized();
        $this->dbQuery($this->adminToken, ['action' => 'select', 'table' => 'halls'])->assertOk();

        $secondToken = $this->postJson('/api/auth/login', ['username' => 'manager', 'password' => 'newpassword1'])->json('data.api_token');
        DB::table('users')->where('id', $managerId)->delete();
        $this->dbQuery($secondToken, ['action' => 'select', 'table' => 'halls'])->assertUnauthorized();
    }

    public function test_company_header_cannot_override_the_token_company(): void
    {
        $hallId = (string) Str::uuid();
        DB::table('halls')->insert(['id' => $hallId, 'name' => 'Rival Hall', 'company_id' => $this->otherCompanyId, 'created_at' => now(), 'updated_at' => now()]);

        $rows = $this->postJson('/api/db/query', ['action' => 'select', 'table' => 'halls'], [
            'X-Api-Token' => $this->adminToken,
            'X-Company-Id' => $this->otherCompanyId,
        ])->assertOk()->json('data');

        $this->assertNotContains($hallId, array_column($rows, 'id'));
    }

    public function test_database_errors_do_not_leak_sql_to_the_browser(): void
    {
        $hallId = (string) Str::uuid();
        DB::table('halls')->insert(['id' => $hallId, 'name' => 'Rival Hall', 'company_id' => $this->otherCompanyId, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->dbQuery($this->adminToken, [
            'action' => 'insert',
            'table' => 'halls',
            'payload' => [['id' => $hallId, 'name' => 'Duplicate']],
        ]);

        $response->assertStatus(409);
        $message = (string) $response->json('error.message');
        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $message);
        $this->assertStringNotContainsStringIgnoringCase('insert into', $message);
    }

    public function test_short_passwords_are_rejected(): void
    {
        $this->dbQuery($this->adminToken, [
            'action' => 'insert',
            'table' => 'users',
            'payload' => ['username' => 'weakling', 'password' => 'abc', 'role' => 'Manager'],
        ])->assertStatus(422);
        $this->assertDatabaseMissing('users', ['username' => 'weakling']);

        $this->postJson('/api/company/register', [
            'company_name' => 'Weak Co',
            'username' => 'weakadmin',
            'password' => '1234',
        ])->assertStatus(422);
    }

    public function test_public_vendor_signup_is_rate_limited_per_ip(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/db/query', [
                'action' => 'insert',
                'table' => 'vendors',
                'payload' => [['vendor_name' => "Spam {$i}", 'category' => 'DJ', 'username' => "spam{$i}", 'password' => 'longpassword1']],
            ])->assertOk();
        }

        $this->postJson('/api/db/query', [
            'action' => 'insert',
            'table' => 'vendors',
            'payload' => [['vendor_name' => 'Spam 6', 'category' => 'DJ', 'username' => 'spam6', 'password' => 'longpassword1']],
        ])->assertStatus(429);
        $this->assertDatabaseMissing('vendors', ['username' => 'spam6']);
    }

    public function test_cors_only_allows_the_app_origin(): void
    {
        config(['cors.allowed_origins' => ['https://sys.banquetdesk.web.lk']]);
        $preflight = ['Access-Control-Request-Method' => 'POST'];

        $evil = $this->options('/api/db/query', [], $preflight + ['Origin' => 'https://evil.example']);
        $this->assertNotContains($evil->headers->get('Access-Control-Allow-Origin'), ['*', 'https://evil.example']);
        $this->options('/api/db/query', [], $preflight + ['Origin' => 'https://sys.banquetdesk.web.lk'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://sys.banquetdesk.web.lk');
    }

    public function test_cdn_scripts_are_pinned_with_subresource_integrity(): void
    {
        foreach (['public/banquetdesk.html', 'public/su-admin.html', 'resources/views/public-menus.blade.php'] as $file) {
            preg_match_all('/<script[^>]+src="https?:\/\/[^"]+"[^>]*>/i', (string) file_get_contents(base_path($file)), $matches);

            $this->assertNotEmpty($matches[0], $file);
            foreach ($matches[0] as $tag) {
                // cdn.tailwindcss.com sends no CORS headers, so SRI would block it; it is version-pinned instead
                if (str_contains($tag, 'cdn.tailwindcss.com/')) {
                    $this->assertStringNotContainsString('crossorigin', $tag, $file);

                    continue;
                }
                $this->assertMatchesRegularExpression('/integrity="sha384-[A-Za-z0-9+\/=]+"/', $tag, $file);
                $this->assertStringContainsString('crossorigin="anonymous"', $tag, $file);
            }
        }
    }

    private function createCompany(string $name): string
    {
        $id = (string) Str::uuid();
        $company = [
            'id' => $id,
            'name' => $name,
            'status' => 'Active',
            'subscription_status' => 'Active',
            'subscription_plan' => 'lifetime',
            'subscription_expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('companies')->insert(array_intersect_key($company, array_flip(Schema::getColumnListing('companies'))));

        return $id;
    }

    /**
     * @param  ?list<string>  $modules
     */
    private function createUser(string $username, string $role, string $companyId, ?array $modules = null): string
    {
        $id = (string) Str::uuid();
        $user = [
            'id' => $id,
            'username' => $username,
            'password' => Hash::make('secret123'),
            'role' => $role,
            'allowed_modules' => $modules === null ? null : json_encode($modules),
            'company_id' => $companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('users')->insert(array_intersect_key($user, array_flip(Schema::getColumnListing('users'))));

        return $id;
    }

    private function createVendor(string $username, ?string $companyId): string
    {
        $id = (string) Str::uuid();
        $vendor = [
            'id' => $id,
            'vendor_name' => $username.' shop',
            'category' => 'Photographer',
            'username' => $username,
            'password' => Hash::make('secret123'),
            'status' => 'Active',
            'company_id' => $companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('vendors')->insert(array_intersect_key($vendor, array_flip(Schema::getColumnListing('vendors'))));

        return $id;
    }

    private function vendorLogin(string $vendorId): string
    {
        $username = DB::table('vendors')->where('id', $vendorId)->value('username');

        return $this->postJson('/api/auth/login', ['username' => $username, 'password' => 'secret123'])
            ->assertOk()
            ->json('data.api_token');
    }

    private function issueToken(string $userId, string $username, string $role, string $companyId): string
    {
        return CompanyApiSession::issue(['user_id' => $userId, 'company_id' => $companyId, 'role' => $role, 'username' => $username]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dbQuery(string $token, array $payload): TestResponse
    {
        return $this->postJson('/api/db/query', $payload, ['X-Api-Token' => $token]);
    }

    private function readBackupJson(string $path): string
    {
        if (str_ends_with($path, '.zip')) {
            $zip = new ZipArchive;
            $zip->open($path);
            $json = (string) $zip->getFromName('full_export.json');
            $zip->close();

            return $json;
        }
        if (str_ends_with($path, '.json.gz')) {
            return (string) gzdecode((string) file_get_contents($path));
        }

        return (string) file_get_contents($path);
    }
}
