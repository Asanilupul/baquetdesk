<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('menu_extras', 'unit')) {
            Schema::table('menu_extras', function (Blueprint $table) {
                $table->string('unit')->default('portion');
            });
        }

        DB::table('menu_extras')->where('type', 'bite')->where(function ($query) {
            $query->whereNull('unit')->orWhere('unit', '');
        })->update(['unit' => 'portion']);

        DB::table('menu_extras')->where('type', 'softdrink')->where(function ($query) {
            $query->whereNull('unit')->orWhere('unit', '')->orWhere('unit', 'portion');
        })->update(['unit' => '1 L']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('menu_extras', 'unit')) {
            Schema::table('menu_extras', function (Blueprint $table) {
                $table->dropColumn('unit');
            });
        }
    }
};
