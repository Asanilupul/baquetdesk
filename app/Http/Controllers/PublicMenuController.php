<?php

namespace App\Http\Controllers;

use App\Support\CompanySubscription;
use App\Support\MenuCategoryOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PublicMenuController extends Controller
{
    public function show(string $token): View
    {
        abort_unless(Schema::hasColumn('companies', 'public_menu_token') && strlen($token) >= 20, 404);

        $company = DB::table('companies')->where('public_menu_token', $token)->first();
        abort_unless($company && CompanySubscription::isUsable($company), 404);

        return view('public-menus', [
            'company' => $company,
            'menus' => $this->menusFor((string) $company->id),
        ]);
    }

    /**
     * @return list<array{id: string, name: string, description: string, hall_prices: list<array{hall: string, price: float}>, categories: list<array{name: string, choice_limit: int, items: list<string>}>}>
     */
    private function menusFor(string $companyId): array
    {
        $menus = DB::table('menus')->where('company_id', $companyId)->orderBy('name')->get();
        if ($menus->isEmpty()) {
            return [];
        }

        $menuIds = $menus->pluck('id')->all();
        $selections = DB::table('menu_selections')->whereIn('menu_id', $menuIds)->get()->groupBy('menu_id');
        $hallPrices = DB::table('menu_hall_prices')->whereIn('menu_id', $menuIds)->where('price', '>', 0)->orderBy('hall_name')->get()->groupBy('menu_id');
        $limits = DB::table('menu_category_configs')->whereIn('menu_id', $menuIds)->get()->groupBy('menu_id');
        $items = DB::table('menu_items')->where('company_id', $companyId)->get()->keyBy('id');
        $categories = DB::table('menu_categories')->where('company_id', $companyId)->get()->keyBy('id');

        return $menus->map(function (object $menu) use ($selections, $hallPrices, $limits, $items, $categories): array {
            $limitByCategory = collect($limits->get($menu->id, []))->pluck('choice_limit', 'category_id');

            $grouped = [];
            foreach ($selections->get($menu->id, []) as $selection) {
                $item = $items->get($selection->item_id);
                if (! $item) {
                    continue;
                }
                $category = $categories->get($item->category_id);
                $key = $category->id ?? '__none';
                $grouped[$key] ??= [
                    'name' => $category->name ?? 'Other',
                    'choice_limit' => (int) ($limitByCategory[$key] ?? 0),
                    'items' => [],
                ];
                $grouped[$key]['items'][] = (string) $item->name;
            }

            foreach ($grouped as &$group) {
                sort($group['items'], SORT_NATURAL | SORT_FLAG_CASE);
            }
            unset($group);

            return [
                'id' => (string) $menu->id,
                'name' => (string) $menu->name,
                'description' => (string) ($menu->description ?? ''),
                'hall_prices' => collect($hallPrices->get($menu->id, []))
                    ->map(fn (object $row): array => ['hall' => (string) $row->hall_name, 'price' => (float) $row->price])
                    ->values()
                    ->all(),
                'categories' => MenuCategoryOrder::sort(array_values($grouped)),
            ];
        })->values()->all();
    }
}
