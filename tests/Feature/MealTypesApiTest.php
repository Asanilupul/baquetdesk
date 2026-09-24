<?php

namespace Tests\Feature;

use App\Support\CompanyApiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MealTypesApiTest extends TestCase
{
    use RefreshDatabase;

    private string $companyId;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (string) Str::uuid();
        $company = [
            'id' => $this->companyId,
            'name' => 'Meal Type Co',
            'status' => 'Active',
            'subscription_status' => 'Active',
            'subscription_plan' => 'lifetime',
            'subscription_expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('companies')->insert(array_intersect_key($company, array_flip(Schema::getColumnListing('companies'))));

        $userId = (string) Str::uuid();
        $user = [
            'id' => $userId,
            'username' => 'mealadmin',
            'password' => Hash::make('secret123'),
            'role' => 'Admin',
            'company_id' => $this->companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('users')->insert(array_intersect_key($user, array_flip(Schema::getColumnListing('users'))));

        $this->token = CompanyApiSession::issue([
            'user_id' => $userId,
            'company_id' => $this->companyId,
            'role' => 'Admin',
            'username' => 'mealadmin',
        ]);
    }

    public function test_meal_type_with_time_duration_can_be_registered_and_is_loaded_in_bootstrap(): void
    {
        $insert = $this->postJson('/api/db/query', [
            'action' => 'insert',
            'table' => 'meal_types',
            'payload' => [
                'id' => (string) Str::uuid(),
                'name' => 'Hi-Tea',
                'start_time' => '15:00',
                'end_time' => '18:00',
                'status' => 'Active',
            ],
        ], $this->headers());

        $insert->assertOk();
        $insert->assertJsonPath('error', null);

        $row = DB::table('meal_types')->where('name', 'Hi-Tea')->first();
        $this->assertSame($this->companyId, $row->company_id);

        DB::table('meal_types')->insert([
            'id' => (string) Str::uuid(),
            'name' => 'Other Company Brunch',
            'company_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bootstrap = $this->getJson('/api/db/bootstrap', $this->headers());

        $bootstrap->assertOk();
        $mealTypes = collect($bootstrap->json('data.meal_types'));
        $hiTea = $mealTypes->firstWhere('name', 'Hi-Tea');
        $this->assertNotNull($hiTea);
        $this->assertSame('15:00', $hiTea['start_time']);
        $this->assertSame('18:00', $hiTea['end_time']);
        $this->assertNull($mealTypes->firstWhere('name', 'Other Company Brunch'));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Api-Token' => $this->token, 'X-Company-Id' => $this->companyId];
    }
}
