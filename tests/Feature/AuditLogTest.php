<?php

namespace Tests\Feature;

use App\Support\CompanyApiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private string $companyId;

    private string $adminToken;

    private string $managerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (string) Str::uuid();
        $company = [
            'id' => $this->companyId,
            'name' => 'Audit Co',
            'status' => 'Active',
            'subscription_status' => 'Active',
            'subscription_plan' => 'lifetime',
            'subscription_expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('companies')->insert(array_intersect_key($company, array_flip(Schema::getColumnListing('companies'))));

        $this->adminToken = $this->issueToken($this->createUser('auditadmin', 'Admin'), 'auditadmin', 'Admin');
        $this->managerToken = $this->issueToken($this->createUser('floormanager', 'Manager'), 'floormanager', 'Manager');
    }

    public function test_create_update_and_delete_are_logged_with_user_and_changes(): void
    {
        $hallId = (string) Str::uuid();

        $this->dbQuery($this->managerToken, ['action' => 'insert', 'table' => 'halls', 'payload' => ['id' => $hallId, 'name' => 'Orchid', 'capacity' => 200]])->assertOk();
        $this->dbQuery($this->managerToken, ['action' => 'update', 'table' => 'halls', 'payload' => ['capacity' => 250], 'filters' => [['column' => 'id', 'operator' => 'eq', 'value' => $hallId]]])->assertOk();
        $this->dbQuery($this->managerToken, ['action' => 'delete', 'table' => 'halls', 'filters' => [['column' => 'id', 'operator' => 'eq', 'value' => $hallId]]])->assertOk();

        $logs = DB::table('audit_logs')->where('record_id', $hallId)->orderBy('id')->get();

        $this->assertSame(['create', 'update', 'delete'], $logs->pluck('action')->all());
        $this->assertSame(['floormanager'], $logs->pluck('username')->unique()->values()->all());
        $this->assertSame($this->companyId, $logs[0]->company_id);
        $this->assertSame('Orchid', $logs[1]->record_label);

        $updateChanges = json_decode($logs[1]->changes, true);
        $this->assertEquals(['old' => 200, 'new' => 250], $updateChanges['capacity']);
        $this->assertArrayNotHasKey('updated_at', $updateChanges);
        $this->assertSame('Orchid', json_decode($logs[2]->changes, true)['name']);
    }

    public function test_update_without_real_changes_is_not_logged(): void
    {
        $hallId = (string) Str::uuid();
        DB::table('halls')->insert(['id' => $hallId, 'name' => 'Tulip', 'company_id' => $this->companyId, 'created_at' => now(), 'updated_at' => now()]);

        $this->dbQuery($this->managerToken, ['action' => 'update', 'table' => 'halls', 'payload' => ['name' => 'Tulip'], 'filters' => [['column' => 'id', 'operator' => 'eq', 'value' => $hallId]]])->assertOk();

        $this->assertSame(0, DB::table('audit_logs')->where('record_id', $hallId)->count());
    }

    public function test_admin_can_filter_audit_log_by_user_and_date(): void
    {
        $this->seedLog('floormanager', 'update', '2026-09-20 10:00:00');
        $this->seedLog('auditadmin', 'delete', '2026-09-20 11:00:00');
        $this->seedLog('floormanager', 'create', '2026-09-10 09:00:00');
        $this->seedLog('outsider', 'create', '2026-09-20 09:00:00', (string) Str::uuid());

        $response = $this->getJson('/api/audit-logs?from=2026-09-20&to=2026-09-20&username=floormanager', ['X-Api-Token' => $this->adminToken]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('update', $response->json('data.logs.0.action'));
        $this->assertSame(['auditadmin', 'floormanager'], $response->json('data.usernames'));
    }

    public function test_date_filter_uses_the_viewers_local_day(): void
    {
        $this->seedLog('floormanager', 'update', '2026-09-19 20:00:00');

        $sriLankaOffset = -330;
        $response = $this->getJson("/api/audit-logs?from=2026-09-20&to=2026-09-20&tz_offset={$sriLankaOffset}", ['X-Api-Token' => $this->adminToken]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_non_admin_cannot_view_audit_log(): void
    {
        $this->getJson('/api/audit-logs', ['X-Api-Token' => $this->managerToken])->assertForbidden();
    }

    public function test_login_is_logged(): void
    {
        $this->postJson('/api/auth/login', ['username' => 'floormanager', 'password' => 'secret123'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'username' => 'floormanager', 'company_id' => $this->companyId]);
    }

    private function createUser(string $username, string $role): string
    {
        $id = (string) Str::uuid();
        $user = [
            'id' => $id,
            'username' => $username,
            'password' => Hash::make('secret123'),
            'role' => $role,
            'company_id' => $this->companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('users')->insert(array_intersect_key($user, array_flip(Schema::getColumnListing('users'))));

        return $id;
    }

    private function issueToken(string $userId, string $username, string $role): string
    {
        return CompanyApiSession::issue(['user_id' => $userId, 'company_id' => $this->companyId, 'role' => $role, 'username' => $username]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dbQuery(string $token, array $payload): TestResponse
    {
        return $this->postJson('/api/db/query', $payload, ['X-Api-Token' => $token, 'X-Company-Id' => $this->companyId]);
    }

    private function seedLog(string $username, string $action, string $createdAt, ?string $companyId = null): void
    {
        DB::table('audit_logs')->insert([
            'company_id' => $companyId ?? $this->companyId,
            'username' => $username,
            'action' => $action,
            'table_name' => 'halls',
            'created_at' => $createdAt,
        ]);
    }
}
