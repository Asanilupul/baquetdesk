<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('combo_packages', function (Blueprint $table) {
            if (! Schema::hasColumn('combo_packages', 'menu_options')) {
                $table->json('menu_options')->nullable()->after('softdrink_lines');
            }
        });

        Schema::table('functions', function (Blueprint $table) {
            if (! Schema::hasColumn('functions', 'combo_menu_option_id')) {
                $table->string('combo_menu_option_id')->nullable()->after('combo_package_id');
            }
            if (! Schema::hasColumn('functions', 'pricing_snapshot')) {
                $table->json('pricing_snapshot')->nullable()->after('combo_menu_option_id');
            }
            if (! Schema::hasColumn('functions', 'optional_vendor_extras')) {
                $table->json('optional_vendor_extras')->nullable()->after('pricing_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('combo_packages', function (Blueprint $table) {
            if (Schema::hasColumn('combo_packages', 'menu_options')) {
                $table->dropColumn('menu_options');
            }
        });

        Schema::table('functions', function (Blueprint $table) {
            foreach (['combo_menu_option_id', 'pricing_snapshot', 'optional_vendor_extras'] as $col) {
                if (Schema::hasColumn('functions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
