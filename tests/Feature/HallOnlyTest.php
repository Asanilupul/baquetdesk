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

class HallOnlyTest extends TestCase
{
    use RefreshDatabase;

    private string $companyId;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->createCompany('Hall Co');
        $adminId = $this->createUser('owner', 'Admin', $this->companyId);
        $this->adminToken = CompanyApiSession::issue(['user_id' => $adminId, 'company_id' => $this->companyId, 'role' => 'Admin', 'username' => 'owner']);
    }

    public function test_admin_can_manage_hall_only_prices_and_inclusions(): void
    {
        $this->dbQuery($this->adminToken, [
            'action' => 'insert',
            'table' => 'halls',
            'payload' => [['id' => 'hall-1', 'name' => 'Grand', 'hall_only_price' => 150000, 'status' => 'Active']],
        ])->assertOk();

        $this->dbQuery($this->adminToken, [
            'action' => 'insert',
            'table' => 'hall_inclusions',
            'payload' => [
                ['id' => 'inc-1', 'name' => 'Chairs', 'price' => 0, 'status' => 'Active'],
                ['id' => 'inc-2', 'name' => 'Sound system', 'price' => 25000, 'status' => 'Active'],
            ],
        ])->assertOk();

        $this->assertEquals(150000, DB::table('halls')->where('id', 'hall-1')->value('hall_only_price'));
        $this->assertSame(2, DB::table('hall_inclusions')->where('company_id', $this->companyId)->count());

        $bootstrap = $this->postJson('/api/db/bootstrap', [], ['X-Api-Token' => $this->adminToken])->assertOk();
        $this->assertSame(['Chairs', 'Sound system'], array_column($bootstrap->json('data.hall_inclusions'), 'name'));
    }

    public function test_staff_without_settings_module_cannot_edit_inclusions(): void
    {
        $managerId = $this->createUser('manager', 'Manager', $this->companyId, ['functions']);
        $managerToken = CompanyApiSession::issue(['user_id' => $managerId, 'company_id' => $this->companyId, 'role' => 'Manager', 'username' => 'manager']);

        $this->dbQuery($managerToken, [
            'action' => 'insert',
            'table' => 'hall_inclusions',
            'payload' => [['id' => 'inc-x', 'name' => 'Stage', 'price' => 0]],
        ])->assertForbidden();

        $this->assertSame(0, DB::table('hall_inclusions')->count());
    }

    public function test_hall_only_function_keeps_its_selected_inclusions(): void
    {
        $inclusions = [
            ['id' => 'inc-1', 'name' => 'Chairs', 'price' => 0],
            ['id' => 'inc-2', 'name' => 'Sound system', 'price' => 25000],
        ];

        $this->dbQuery($this->adminToken, [
            'action' => 'insert',
            'table' => 'functions',
            'payload' => [[
                'id' => 'fn-1',
                'customer_name' => 'Nimal',
                'customer_phone' => '0770000000',
                'function_date' => '2026-10-10',
                'pax_count' => 120,
                'hall_name' => 'Grand',
                'package_source' => 'hall_only',
                'function_menu' => '',
                'menu_price' => 0,
                'hall_charge' => 175000,
                'hall_inclusions' => $inclusions,
            ]],
        ])->assertOk();

        $row = $this->dbQuery($this->adminToken, [
            'action' => 'select',
            'table' => 'functions',
            'filters' => [['column' => 'id', 'op' => 'eq', 'value' => 'fn-1']],
        ])->assertOk()->json('data.0');

        $this->assertSame('hall_only', $row['package_source']);
        $this->assertSame($inclusions, $row['hall_inclusions']);
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dbQuery(string $token, array $payload): TestResponse
    {
        return $this->postJson('/api/db/query', $payload, ['X-Api-Token' => $token]);
    }
}
