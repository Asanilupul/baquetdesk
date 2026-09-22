<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stewards', function (Blueprint $table) {
            if (! Schema::hasColumn('stewards', 'charge')) {
                $table->decimal('charge', 14, 2)->nullable()->after('late_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stewards', function (Blueprint $table) {
            if (Schema::hasColumn('stewards', 'charge')) {
                $table->dropColumn('charge');
            }
        });
    }
};
