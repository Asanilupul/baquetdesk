<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('functions', function (Blueprint $table) {
            if (! Schema::hasColumn('functions', 'customer_nic')) {
                $table->string('customer_nic')->nullable()->after('customer_phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('functions', function (Blueprint $table) {
            if (Schema::hasColumn('functions', 'customer_nic')) {
                $table->dropColumn('customer_nic');
            }
        });
    }
};
