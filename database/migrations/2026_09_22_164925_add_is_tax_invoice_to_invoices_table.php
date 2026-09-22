<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'is_tax_invoice')) {
                $table->boolean('is_tax_invoice')->default(true)->after('document_kind');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'is_tax_invoice')) {
                $table->dropColumn('is_tax_invoice');
            }
        });
    }
};
