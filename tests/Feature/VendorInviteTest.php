<?php

namespace Tests\Feature;

use App\Support\CompanyApiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorInviteTest extends TestCase
{
    use RefreshDatabase;

    private string $companyId;

    private string $otherCompanyId;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->createCompany('Target Co');
        $this->otherCompanyId = $this->createCompany('Other Co');
        $this->adminToken = $this->tokenFor($this->createUser('owner', 'Admin', $this->companyId), 'owner', 'Admin', $this->companyId);
    }

    public function test_invite_link_registers_the_vendor_into_the_sending_company_only(): void
    {
        $token = $this->createInvite($this->adminToken);

        $this->getJson('/api/vendor-invites/check?token='.$token)
            ->assertOk()
            ->assertJsonPath('data.company_name', 'Target Co');

        $this->postJson('/api/vendor-invites/register', $this->vendorPayload($token, 'photo'))->assertOk();

        $vendor = DB::table('vendors')->where('username', 'photo')->first();
        $this->assertSame($this->companyId, $vendor->company_id);
        $this->assertTrue(Hash::check('longpassword1', $vendor->password));

        $otherToken = $this->tokenFor($this->createUser('other', 'Admin', $this->otherCompanyId), 'other', 'Admin', $this->otherCompanyId);
        $otherVendors = $this->postJson('/api/db/query', ['action' => 'select', 'table' => 'vendors'], ['X-Api-Token' => $otherToken])
            ->assertOk()
            ->json('data');
        $this->assertSame([], $otherVendors);
    }

    public function test_invite_link_cannot_be_used_twice(): void
    {
        $token = $this->createInvite($this->adminToken);

        $this->postJson('/api/vendor-invites/register', $this->vendorPayload($token, 'first'))->assertOk();
        $this->postJson('/api/vendor-invites/register', $this->vendorPayload($token, 'second'))->assertStatus(410);
        $this->getJson('/api/vendor-invites/check?token='.$token)->assertNotFound();

        $this->assertSame(0, DB::table('vendors')->where('username', 'second')->count());
    }

    public function test_each_invite_is_a_different_link(): void
    {
        $first = $this->createInvite($this->adminToken);
        $second = $this->createInvite($this->adminToken);

        $this->assertNotSame($first, $second);
    }

    public function test_revoked_expired_and_unknown_links_are_rejected(): void
    {
        $revoked = $this->createInvite($this->adminToken);
        $inviteId = DB::table('vendor_invites')->orderByDesc('created_at')->value('id');
        $this->postJson("/api/vendor-invites/{$inviteId}/revoke", [], ['X-Api-Token' => $this->adminToken])->assertOk();

        $expired = $this->createInvite($this->adminToken);
        DB::table('vendor_invites')->whereNull('revoked_at')->update(['expires_at' => now()->subDay()]);

        foreach ([$revoked, $expired, 'not-a-real-token'] as $token) {
            $this->postJson('/api/vendor-invites/register', $this->vendorPayload($token, 'v'.Str::random(6)))->assertStatus(410);
        }
        $this->assertSame(0, DB::table('vendors')->count());
    }

    public function test_invite_registration_cannot_reuse_a_staff_username(): void
    {
        $token = $this->createInvite($this->adminToken);

        $this->postJson('/api/vendor-invites/register', $this->vendorPayload($token, 'owner'))->assertStatus(422);

        $this->assertSame(0, DB::table('vendors')->count());
        $this->getJson('/api/vendor-invites/check?token='.$token)->assertOk();
    }

    public function test_staff_without_vendor_module_cannot_create_invites(): void
    {
        $managerToken = $this->tokenFor($this->createUser('mgr', 'Manager', $this->companyId, ['functions']), 'mgr', 'Manager', $this->companyId);

        $this->postJson('/api/vendor-invites', [], ['X-Api-Token' => $managerToken])->assertForbidden();
        $this->postJson('/api/vendor-invites')->assertUnauthorized();
    }

    public function test_super_admin_sees_vendors_by_company_and_sends_invites_to_selected_companies(): void
    {
        DB::table('vendors')->insert([
            'id' => (string) Str::uuid(),
            'vendor_name' => 'Studio One',
            'category' => 'Photographer',
            'username' => 'studio',
            'password' => Hash::make('secret123'),
            'status' => 'Active',
            'company_id' => $this->companyId,
        ]);
        $suToken = Str::random(64);
        Cache::put('su_admin_token:'.$suToken, ['user_id' => 'su', 'username' => 'suadmin', 'role' => 'SuperAdmin'], 600);

        $groups = collect($this->getJson('/api/su/vendors', ['X-SU-Token' => $suToken])->assertOk()->json('data'))->keyBy('company_id');
        $this->assertSame(['Studio One'], array_column($groups[$this->companyId]['vendors'], 'vendor_name'));
        $this->assertArrayNotHasKey('password', $groups[$this->companyId]['vendors'][0]);
        $this->assertSame([], $groups[$this->otherCompanyId]['vendors']);

        $links = $this->postJson('/api/su/vendor-invites', ['company_ids' => [$this->otherCompanyId]], ['X-SU-Token' => $suToken])
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $links);
        $token = explode('vendor_invite=', $links[0]['link'])[1];

        $this->postJson('/api/vendor-invites/register', $this->vendorPayload($token, 'djmike'))->assertOk();
        $this->assertSame($this->otherCompanyId, DB::table('vendors')->where('username', 'djmike')->value('company_id'));

        $this->getJson('/api/su/vendors')->assertUnauthorized();
    }

    private function createInvite(string $apiToken): string
    {
        $link = $this->postJson('/api/vendor-invites', ['note' => 'Photographer'], ['X-Api-Token' => $apiToken])
            ->assertOk()
            ->json('data.link');

        return explode('vendor_invite=', $link)[1];
    }

    /**
     * @return array<string, string>
     */
    private function vendorPayload(string $token, string $username): array
    {
        return [
            'token' => $token,
            'vendor_name' => ucfirst($username).' Services',
            'category' => 'Photographer',
            'username' => $username,
            'password' => 'longpassword1',
            'company_id' => $this->otherCompanyId,
        ];
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
        DB::table('users')->insert(array_intersect_key([
            'id' => $id,
            'username' => $username,
            'password' => Hash::make('secret123'),
            'role' => $role,
            'allowed_modules' => $modules === null ? null : json_encode($modules),
            'company_id' => $companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ], array_flip(Schema::getColumnListing('users'))));

        return $id;
    }

    private function tokenFor(string $userId, string $username, string $role, string $companyId): string
    {
        return CompanyApiSession::issue(['user_id' => $userId, 'company_id' => $companyId, 'role' => $role, 'username' => $username]);
    }
}
