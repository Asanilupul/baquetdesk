<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'tax_percent')) {
                $table->decimal('tax_percent', 8, 2)->default(0)->after('is_tax_invoice');
            }
            if (! Schema::hasColumn('invoices', 'tax_amount')) {
                $table->decimal('tax_amount', 14, 2)->default(0)->after('tax_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            foreach (['tax_amount', 'tax_percent'] as $col) {
                if (Schema::hasColumn('invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
