<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->string('subtitle')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->text('address')->nullable();
                $table->text('logo')->nullable();
                $table->string('status')->default('Active');
                $table->timestamps();
            });
        }

        $now = now();
        $companyName = (string) (DB::table('system_settings')->where('key', 'company_name')->value('value') ?? '');
        $companyName = trim($companyName) !== '' ? trim($companyName) : 'Default Company';

        $defaultId = (string) Str::uuid();
        $existing = DB::table('companies')->first();
        if (! $existing) {
            DB::table('companies')->insert([
                'id' => $defaultId,
                'name' => $companyName,
                'subtitle' => (string) (DB::table('system_settings')->where('key', 'company_subtitle')->value('value') ?? ''),
                'phone' => (string) (DB::table('system_settings')->where('key', 'company_phone')->value('value') ?? ''),
                'email' => (string) (DB::table('system_settings')->where('key', 'company_email')->value('value') ?? ''),
                'address' => (string) (DB::table('system_settings')->where('key', 'company_address')->value('value') ?? ''),
                'logo' => (string) (DB::table('system_settings')->where('key', 'company_logo')->value('value') ?? ''),
                'status' => 'Active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $defaultId = $existing->id;
        }

        $tables = [
            'users', 'employees', 'stewards', 'attendance', 'advance_requests', 'payments',
            'functions', 'walking_inquiries', 'quotations', 'invoices', 'suppliers',
            'supplier_products', 'purchase_orders', 'supplier_payments', 'menu_categories',
            'menu_items', 'menus', 'menu_hall_prices', 'menu_category_configs', 'menu_selections',
            'function_menu_selections', 'menu_addons', 'function_menu_addons', 'function_menu_extras',
            'kitchen_sheets', 'store_items', 'item_recipes', 'store_transactions', 'vendors',
            'vendor_categories', 'vendor_packages', 'accounts_coa', 'journal_entries', 'expense_entries',
            'function_sheets', 'production_balancing', 'halls', 'menu_extras', 'combo_packages',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->string('company_id')->nullable()->index();
                });
            }
            DB::table($table)->whereNull('company_id')->update(['company_id' => $defaultId]);
        }
    }

    public function down(): void
    {
        $tables = [
            'users', 'employees', 'stewards', 'attendance', 'advance_requests', 'payments',
            'functions', 'walking_inquiries', 'quotations', 'invoices', 'suppliers',
            'supplier_products', 'purchase_orders', 'supplier_payments', 'menu_categories',
            'menu_items', 'menus', 'menu_hall_prices', 'menu_category_configs', 'menu_selections',
            'function_menu_selections', 'menu_addons', 'function_menu_addons', 'function_menu_extras',
            'kitchen_sheets', 'store_items', 'item_recipes', 'store_transactions', 'vendors',
            'vendor_categories', 'vendor_packages', 'accounts_coa', 'journal_entries', 'expense_entries',
            'function_sheets', 'production_balancing', 'halls', 'menu_extras', 'combo_packages',
        ];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('company_id');
                });
            }
        }
        Schema::dropIfExists('companies');
    }
};
