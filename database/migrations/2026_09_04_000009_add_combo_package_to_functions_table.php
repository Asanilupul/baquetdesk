<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('functions', function (Blueprint $table) {
            $table->string('package_source')->default('menu')->after('function_menu');
            $table->string('combo_package_id')->nullable()->after('package_source');
        });
    }

    public function down(): void
    {
        Schema::table('functions', function (Blueprint $table) {
            $table->dropColumn(['package_source', 'combo_package_id']);
        });
    }
};
