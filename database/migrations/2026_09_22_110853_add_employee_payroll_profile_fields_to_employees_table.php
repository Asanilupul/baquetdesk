<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'nic')) {
                $table->string('nic')->nullable()->after('empNo');
            }
            if (! Schema::hasColumn('employees', 'phone')) {
                $table->string('phone')->nullable()->after('nic');
            }
            if (! Schema::hasColumn('employees', 'address')) {
                $table->text('address')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('employees', 'designation')) {
                $table->string('designation')->nullable()->after('section');
            }
            if (! Schema::hasColumn('employees', 'gender')) {
                $table->string('gender', 32)->nullable()->after('designation');
            }
            if (! Schema::hasColumn('employees', 'employment_type')) {
                $table->string('employment_type', 32)->nullable()->after('gender');
            }
            if (! Schema::hasColumn('employees', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('employment_type');
            }
            if (! Schema::hasColumn('employees', 'bank_account')) {
                $table->string('bank_account')->nullable()->after('bank_name');
            }
            if (! Schema::hasColumn('employees', 'is_epf_employee')) {
                $table->boolean('is_epf_employee')->default(true)->after('otherAllowance');
            }
            if (! Schema::hasColumn('employees', 'epfEmployer')) {
                $table->decimal('epfEmployer', 8, 2)->default(12)->after('epfEmployee');
            }
            if (! Schema::hasColumn('employees', 'etfRate')) {
                $table->decimal('etfRate', 8, 2)->default(3)->after('epfEmployer');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            foreach ([
                'nic', 'phone', 'address', 'designation', 'gender', 'employment_type',
                'bank_name', 'bank_account', 'is_epf_employee', 'epfEmployer', 'etfRate',
            ] as $col) {
                if (Schema::hasColumn('employees', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
