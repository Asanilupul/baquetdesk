<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('empNo')->nullable();
            $table->string('birthday')->nullable();
            $table->string('joinDate')->nullable();
            $table->string('status')->default('Active');
            $table->string('section')->nullable();
            $table->decimal('basicSalary', 14, 2)->default(0);
            $table->decimal('responsibilityAllowance', 14, 2)->default(0);
            $table->decimal('attendanceAllowance', 14, 2)->default(0);
            $table->decimal('otherAllowance', 14, 2)->default(0);
            $table->decimal('epfEmployee', 8, 2)->default(8);
            $table->decimal('welfare', 14, 2)->default(0);
            $table->decimal('mealDeduction', 14, 2)->default(0);
            $table->decimal('deposit', 14, 2)->default(0);
            $table->decimal('dayRate', 14, 2)->default(0);
            $table->decimal('leaves', 8, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('stewards', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('hall')->nullable();
            $table->string('billNumber')->nullable();
            $table->string('status')->default('PRESENT');
            $table->boolean('isPaid')->default(false);
            $table->string('date')->nullable();
            $table->decimal('late_amount', 14, 2)->default(0);
            $table->string('function_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->timestamps();
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('date');
            $table->string('employee_id');
            $table->string('status')->default('PRESENT');
            $table->timestamps();
            $table->unique(['date', 'employee_id']);
        });

        Schema::create('advance_requests', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('employee_id');
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('date')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->boolean('is_reconciled')->default(false);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('bill_number')->nullable();
            $table->decimal('bill_amount', 14, 2)->default(0);
            $table->string('bill_date')->nullable();
            $table->string('function_date')->nullable();
            $table->string('function_id')->nullable();
            $table->string('payment_type')->nullable();
            $table->decimal('steward_charges', 14, 2)->default(0);
            $table->decimal('steward_charge', 14, 2)->default(0);
            $table->decimal('handover_amount', 14, 2)->default(0);
            $table->string('handover_method')->nullable();
            $table->boolean('is_reconciled')->default(false);
            $table->boolean('is_refunded')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('functions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->string('function_date')->nullable();
            $table->integer('pax_count')->default(0);
            $table->string('function_type')->nullable();
            $table->string('hall_name')->nullable();
            $table->string('meal_type')->nullable();
            $table->string('function_menu')->nullable();
            $table->string('bill_number')->nullable();
            $table->decimal('menu_price', 12, 2)->default(0);
            $table->decimal('hall_charge', 12, 2)->default(0);
            $table->string('menu_discount_type')->default('none');
            $table->decimal('menu_discount_value', 12, 2)->default(0);
            $table->string('menu_discount_scope')->default('menu');
            $table->text('menu_discount_narration')->nullable();
            $table->decimal('payment_amount', 14, 2)->nullable();
            $table->string('payment_type')->nullable();
            $table->string('invoice_date')->nullable();
            $table->timestamps();
        });

        Schema::create('walking_inquiries', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->string('inquiry_date')->nullable();
            $table->string('expected_function_date')->nullable();
            $table->integer('pax_count')->default(0);
            $table->string('function_type')->nullable();
            $table->string('preferred_hall')->nullable();
            $table->string('meal_type')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('Pending');
            $table->timestamps();
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('quotation_number')->nullable();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->string('event_date')->nullable();
            $table->json('items')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('company_name')->nullable();
            $table->string('company_subtitle')->nullable();
            $table->string('company_phone')->nullable();
            $table->text('company_logo')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('invoice_number')->unique();
            $table->string('function_id')->nullable();
            $table->string('bill_number')->nullable();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->string('event_date')->nullable();
            $table->string('function_type')->nullable();
            $table->string('hall_name')->nullable();
            $table->string('meal_type')->nullable();
            $table->string('function_menu')->nullable();
            $table->integer('pax_count')->default(0);
            $table->json('items')->nullable();
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->text('discount_narration')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('company_name')->nullable();
            $table->string('company_subtitle')->nullable();
            $table->string('company_phone')->nullable();
            $table->text('company_logo')->nullable();
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('contact_number')->nullable();
            $table->string('contact_number_2')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('supplier_id')->nullable();
            $table->string('product_name');
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->string('unit')->default('pcs');
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('po_number')->nullable();
            $table->string('supplier_id')->nullable();
            $table->json('items')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('status')->default('Pending');
            $table->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('supplier_id')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('payment_date')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('reference_no')->nullable();
            $table->timestamps();
        });

        Schema::create('menu_categories', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('item_limit')->default(0);
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('category_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('menu_hall_prices', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('menu_id');
            $table->string('hall_name')->nullable();
            $table->decimal('price', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('menu_category_configs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('menu_id')->nullable();
            $table->string('category_id')->nullable();
            $table->integer('choice_limit')->default(0);
            $table->timestamps();
        });

        Schema::create('menu_selections', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('menu_id')->nullable();
            $table->string('item_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->timestamps();
        });

        Schema::create('function_menu_selections', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('function_id');
            $table->string('item_id')->nullable();
            $table->timestamps();
        });

        Schema::create('menu_addons', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('function_menu_addons', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('function_id');
            $table->string('addon_id');
            $table->decimal('price', 10, 2)->default(0);
            $table->timestamps();
            $table->unique(['function_id', 'addon_id']);
        });

        Schema::create('kitchen_sheets', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('function_id')->nullable();
            $table->string('function_date')->nullable();
            $table->integer('pax_count')->default(0);
            $table->string('hall_name')->nullable();
            $table->string('meal_type')->nullable();
            $table->string('menu_name')->nullable();
            $table->json('selected_items')->nullable();
            $table->json('bites')->nullable();
            $table->json('soft_drinks')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('store_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->decimal('current_stock', 14, 2)->default(0);
            $table->string('unit')->default('pcs');
            $table->timestamps();
        });

        Schema::create('item_recipes', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('menu_item_id')->nullable();
            $table->string('product_id')->nullable();
            $table->decimal('quantity', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('store_transactions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('item_id')->nullable();
            $table->string('transaction_type')->nullable();
            $table->decimal('quantity', 14, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('vendor_name');
            $table->string('category')->nullable();
            $table->string('email')->nullable();
            $table->text('description')->nullable();
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->json('pictures')->nullable();
            $table->json('packages')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('Active');
            $table->timestamps();
        });

        Schema::create('vendor_categories', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('vendor_packages', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->json('categories')->nullable();
            $table->timestamps();
        });

        Schema::create('accounts_coa', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('account_code')->nullable();
            $table->string('account_name');
            $table->string('account_type')->nullable();
            $table->decimal('balance', 14, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('entry_date')->nullable();
            $table->string('reference_no')->nullable();
            $table->string('account_id')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_type')->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('function_sheets', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('function_id')->unique();
            $table->json('sheet_data')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamps();
        });

        Schema::create('production_balancing', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('date')->unique();
            $table->json('in_store_items')->nullable();
            $table->json('processing_items')->nullable();
            $table->json('daily_sales')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $tables = [
            'production_balancing', 'system_settings', 'function_sheets', 'journal_entries',
            'accounts_coa', 'vendor_packages', 'vendor_categories', 'vendors', 'store_transactions',
            'item_recipes', 'store_items', 'kitchen_sheets', 'function_menu_addons', 'menu_addons',
            'function_menu_selections', 'menu_selections', 'menu_category_configs', 'menu_hall_prices',
            'menus', 'menu_items', 'menu_categories', 'supplier_payments', 'purchase_orders',
            'supplier_products', 'suppliers', 'invoices', 'quotations', 'walking_inquiries',
            'functions', 'payments', 'advance_requests', 'attendance', 'stewards', 'employees',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
