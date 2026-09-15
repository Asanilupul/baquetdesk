<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('menu_extras', 'price')) {
            return;
        }

        Schema::table('menu_extras', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->default(0);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('menu_extras', 'price')) {
            return;
        }

        Schema::table('menu_extras', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
