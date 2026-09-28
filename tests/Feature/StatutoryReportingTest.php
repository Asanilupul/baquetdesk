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

class StatutoryReportingTest extends TestCase
{
    use RefreshDatabase;

    private string $companyId;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->createCompany('Tax Co');
        $adminId = $this->createUser('owner', 'Admin', $this->companyId);
        $this->adminToken = CompanyApiSession::issue(['user_id' => $adminId, 'company_id' => $this->companyId, 'role' => 'Admin', 'username' => 'owner']);
    }

    public function test_admin_can_register_fixed_assets_and_bank_clearings(): void
    {
        $this->dbQuery($this->adminToken, [
            'action' => 'insert',
            'table' => 'fixed_assets',
            'payload' => [[
                'id' => 'fa-1',
                'asset_code' => 'FA-001',
                'name' => 'Generator',
                'category' => 'Plant & Machinery',
                'acquisition_date' => '2026-04-01',
                'cost' => 1200000,
                'useful_life_years' => 10,
                'capital_allowance_rate' => 20,
            ]],
        ])->assertOk();

        $this->dbQuery($this->adminToken, [
            'action' => 'insert',
            'table' => 'bank_clearings',
            'payload' => [['id' => 'bc-1', 'journal_entry_id' => 'je-1', 'cleared_at' => '2026-09-30']],
        ])->assertOk();

        $bootstrap = $this->postJson('/api/db/bootstrap', [], ['X-Api-Token' => $this->adminToken])->assertOk();
        $this->assertSame(['Generator'], array_column($bootstrap->json('data.fixed_assets'), 'name'));
        $this->assertSame(['je-1'], array_column($bootstrap->json('data.bank_clearings'), 'journal_entry_id'));
        $this->assertSame($this->companyId, DB::table('fixed_assets')->where('id', 'fa-1')->value('company_id'));
    }

    public function test_staff_without_accounts_module_cannot_edit_fixed_assets(): void
    {
        $managerId = $this->createUser('manager', 'Manager', $this->companyId, ['functions']);
        $managerToken = CompanyApiSession::issue(['user_id' => $managerId, 'company_id' => $this->companyId, 'role' => 'Manager', 'username' => 'manager']);

        $this->dbQuery($managerToken, [
            'action' => 'insert',
            'table' => 'fixed_assets',
            'payload' => [['id' => 'fa-x', 'name' => 'Van', 'cost' => 5000000]],
        ])->assertForbidden();

        $this->assertSame(0, DB::table('fixed_assets')->count());
    }

    public function test_expense_and_employee_tax_fields_are_saved(): void
    {
        $this->dbQuery($this->adminToken, [
            'action' => 'insert',
            'table' => 'expense_entries',
            'payload' => [[
                'id' => 'exp-1',
                'expense_date' => '2026-09-10',
                'payee' => 'Lanka Suppliers',
                'amount' => 118000,
                'vat_amount' => 18000,
                'wht_amount' => 5000,
                'wht_type' => 'Service fees',
                'supplier_tin' => '123456789',
                'supplier_vat_no' => '123456789-7000',
                'supplier_invoice_no' => 'INV-77',
            ]],
        ])->assertOk();

        $this->dbQuery($this->adminToken, [
            'action' => 'insert',
            'table' => 'employees',
            'payload' => [['id' => 'emp-1', 'name' => 'Kamal', 'tin' => '987654321', 'apitMonthly' => 3500]],
        ])->assertOk();

        $expense = DB::table('expense_entries')->where('id', 'exp-1')->first();
        $this->assertEquals(18000, $expense->vat_amount);
        $this->assertEquals(5000, $expense->wht_amount);
        $this->assertSame('123456789-7000', $expense->supplier_vat_no);

        $employee = DB::table('employees')->where('id', 'emp-1')->first();
        $this->assertSame('987654321', $employee->tin);
        $this->assertEquals(3500, $employee->apitMonthly);
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
