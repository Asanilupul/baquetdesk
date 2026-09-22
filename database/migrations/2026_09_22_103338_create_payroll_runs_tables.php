<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('company_id')->nullable()->index();
                $table->string('period', 7)->index();
                $table->string('status', 32)->default('draft');
                $table->string('generated_at')->nullable();
                $table->string('locked_at')->nullable();
                $table->string('paid_at')->nullable();
                $table->string('generated_by')->nullable();
                $table->text('notes')->nullable();
                $table->json('totals')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payroll_slips')) {
            Schema::create('payroll_slips', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('company_id')->nullable()->index();
                $table->string('run_id')->index();
                $table->string('employee_id')->index();
                $table->json('employee_snapshot')->nullable();
                $table->json('components')->nullable();
                $table->string('pay_status', 32)->default('unpaid');
                $table->string('paid_at')->nullable();
                $table->string('paid_method')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_slips');
        Schema::dropIfExists('payroll_runs');
    }
};
