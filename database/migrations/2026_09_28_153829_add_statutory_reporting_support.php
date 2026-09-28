<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_entries', function (Blueprint $table) {
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('wht_amount', 14, 2)->default(0);
            $table->string('wht_type')->nullable();
            $table->string('supplier_tin')->nullable();
            $table->string('supplier_vat_no')->nullable();
            $table->string('supplier_invoice_no')->nullable();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('tin')->nullable();
            $table->decimal('apitMonthly', 14, 2)->nullable();
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('asset_code')->nullable();
            $table->string('name');
            $table->string('category')->nullable();
            $table->date('acquisition_date')->nullable();
            $table->decimal('cost', 14, 2)->default(0);
            $table->decimal('residual_value', 14, 2)->default(0);
            $table->decimal('useful_life_years', 6, 2)->default(5);
            $table->string('depreciation_method')->default('straight_line');
            $table->decimal('capital_allowance_rate', 6, 2)->default(20);
            $table->date('disposal_date')->nullable();
            $table->decimal('disposal_value', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('Active');
            $table->string('company_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('bank_clearings', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('journal_entry_id')->index();
            $table->date('cleared_at');
            $table->string('statement_ref')->nullable();
            $table->string('company_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_clearings');
        Schema::dropIfExists('fixed_assets');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['tin', 'apitMonthly']);
        });

        Schema::table('expense_entries', function (Blueprint $table) {
            $table->dropColumn(['vat_amount', 'wht_amount', 'wht_type', 'supplier_tin', 'supplier_vat_no', 'supplier_invoice_no']);
        });
    }
};
