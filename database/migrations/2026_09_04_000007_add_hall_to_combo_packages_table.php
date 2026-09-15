<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('combo_packages', function (Blueprint $table) {
            $table->string('hall_name')->nullable()->after('menu_id');
            $table->decimal('menu_unit_price', 14, 2)->default(0)->after('hall_name');
            $table->integer('pax_count')->default(0)->after('menu_unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('combo_packages', function (Blueprint $table) {
            $table->dropColumn(['hall_name', 'menu_unit_price', 'pax_count']);
        });
    }
};
