<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'subscription_plan')) {
                $table->string('subscription_plan')->nullable()->after('status');
            }
            if (! Schema::hasColumn('companies', 'subscription_expires_at')) {
                $table->timestamp('subscription_expires_at')->nullable()->after('subscription_plan');
            }
            if (! Schema::hasColumn('companies', 'subscription_status')) {
                $table->string('subscription_status')->default('Pending')->after('subscription_expires_at');
            }
        });

        // Preserve access for companies that already exist at migrate time
        DB::table('companies')->update([
            'subscription_plan' => 'lifetime',
            'subscription_expires_at' => null,
            'subscription_status' => 'Active',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            foreach (['subscription_status', 'subscription_expires_at', 'subscription_plan'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
