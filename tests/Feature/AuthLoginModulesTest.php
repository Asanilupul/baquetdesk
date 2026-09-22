<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthLoginModulesTest extends TestCase
{
    use RefreshDatabase;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (string) Str::uuid();

        if (Schema::hasTable('companies')) {
            $row = [
                'id' => $this->companyId,
                'name' => 'Rockhouse Test',
                'status' => 'Active',
                'subscription_status' => 'Active',
                'subscription_plan' => 'lifetime',
                'subscription_expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $cols = Schema::getColumnListing('companies');
            DB::table('companies')->insert(array_intersect_key($row, array_flip($cols)));
        }
    }

    private function insertUser(?string $allowedModulesJson): string
    {
        $id = (string) Str::uuid();
        $row = [
            'id' => $id,
            'username' => 'rockhouse',
            'password' => Hash::make('secret123'),
            'role' => 'Admin',
            'company_id' => $this->companyId,
            'allowed_modules' => $allowedModulesJson,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $cols = Schema::getColumnListing('users');
        DB::table('users')->insert(array_intersect_key($row, array_flip($cols)));

        return $id;
    }

    public function test_login_decodes_allowed_modules_json_string_to_array(): void
    {
        $this->insertUser(json_encode(['dashboard', 'hr', 'users', 'reports', 'settings']));

        $response = $this->postJson('/api/auth/login', [
            'username' => 'rockhouse',
            'password' => 'secret123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('error', null);
        $modules = $response->json('data.allowed_modules');
        $this->assertIsArray($modules);
        $this->assertContains('hr', $modules);
        $this->assertContains('users', $modules);
    }

    public function test_login_keeps_null_allowed_modules_as_unrestricted(): void
    {
        $this->insertUser(null);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'rockhouse',
            'password' => 'secret123',
        ]);

        $response->assertOk();
        $this->assertNull($response->json('data.allowed_modules'));
    }
}
