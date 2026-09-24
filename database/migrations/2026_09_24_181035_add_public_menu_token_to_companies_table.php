<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies') || Schema::hasColumn('companies', 'public_menu_token')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->string('public_menu_token', 64)->nullable()->unique();
        });

        DB::table('companies')->whereNull('public_menu_token')->pluck('id')->each(function ($id) {
            DB::table('companies')->where('id', $id)->update(['public_menu_token' => Str::random(40)]);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'public_menu_token')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['public_menu_token']);
            $table->dropColumn('public_menu_token');
        });
    }
};
