<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicMenuPageTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (string) Str::uuid();
        $this->token = Str::random(40);

        $this->insertCompany($this->companyId, $this->token, 'Active');
        $this->seedMenu($this->companyId, 'Gold Wedding Menu');
    }

    public function test_guest_can_view_company_menus_without_login(): void
    {
        $response = $this->get(route('public-menus.show', $this->token));

        $response->assertOk();
        $response->assertSeeInOrder(['Gold Wedding Menu', 'SOUP', 'BREAD', 'BUTTER']);
        $response->assertSee('Choose 1 of 2');
        $response->assertSee('Cream of Mushroom');
        $response->assertSee('2,500.00');
    }

    public function test_menus_from_other_companies_are_not_shown(): void
    {
        $otherCompanyId = (string) Str::uuid();
        $this->insertCompany($otherCompanyId, Str::random(40), 'Active');
        $this->seedMenu($otherCompanyId, 'Rival Secret Menu');

        $response = $this->get(route('public-menus.show', $this->token));

        $response->assertOk();
        $response->assertDontSee('Rival Secret Menu');
    }

    public function test_unknown_token_returns_not_found(): void
    {
        $this->get(route('public-menus.show', Str::random(40)))->assertNotFound();
    }

    public function test_deactivated_company_menus_are_hidden(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update(['status' => 'Inactive']);

        $this->get(route('public-menus.show', $this->token))->assertNotFound();
    }

    private function insertCompany(string $id, string $token, string $status): void
    {
        $row = [
            'id' => $id,
            'name' => 'Rockhouse Test',
            'status' => $status,
            'subscription_status' => 'Active',
            'subscription_plan' => 'lifetime',
            'subscription_expires_at' => null,
            'public_menu_token' => $token,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('companies')->insert(array_intersect_key($row, array_flip(Schema::getColumnListing('companies'))));
    }

    private function seedMenu(string $companyId, string $menuName): void
    {
        $now = now();
        $menuId = (string) Str::uuid();
        $categories = ['Butter' => 0, 'Soup' => 1, 'Bread' => 0];
        $items = ['Soup' => ['Cream of Mushroom', 'Chicken Soup'], 'Bread' => ['Garlic Bread'], 'Butter' => ['Herb Butter']];

        DB::table('menus')->insert(['id' => $menuId, 'name' => $menuName, 'company_id' => $companyId, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('menu_hall_prices')->insert(['id' => (string) Str::uuid(), 'menu_id' => $menuId, 'hall_name' => 'Orchid', 'price' => 2500, 'company_id' => $companyId, 'created_at' => $now, 'updated_at' => $now]);

        foreach ($categories as $categoryName => $choiceLimit) {
            $categoryId = (string) Str::uuid();
            DB::table('menu_categories')->insert(['id' => $categoryId, 'name' => $categoryName, 'company_id' => $companyId, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('menu_category_configs')->insert(['id' => (string) Str::uuid(), 'menu_id' => $menuId, 'category_id' => $categoryId, 'choice_limit' => $choiceLimit, 'company_id' => $companyId, 'created_at' => $now, 'updated_at' => $now]);

            foreach ($items[$categoryName] as $itemName) {
                $itemId = (string) Str::uuid();
                DB::table('menu_items')->insert(['id' => $itemId, 'category_id' => $categoryId, 'name' => $itemName, 'company_id' => $companyId, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('menu_selections')->insert(['id' => (string) Str::uuid(), 'menu_id' => $menuId, 'item_id' => $itemId, 'company_id' => $companyId, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
