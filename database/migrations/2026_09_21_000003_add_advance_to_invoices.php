<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'advance_amount')) {
                $table->decimal('advance_amount', 14, 2)->default(0)->after('discount_narration');
            }
            if (! Schema::hasColumn('invoices', 'balance_due')) {
                $table->decimal('balance_due', 14, 2)->default(0)->after('total_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            foreach (['advance_amount', 'balance_due'] as $col) {
                if (Schema::hasColumn('invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
