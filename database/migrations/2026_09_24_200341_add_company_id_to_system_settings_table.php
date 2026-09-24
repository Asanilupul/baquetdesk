<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * system_settings was keyed by `key` alone, so every tenant shared one row per setting.
     * Rebuild it with a surrogate id and a (company_id, key) unique pair; existing rows stay
     * global (company_id NULL) and act as defaults until a company saves its own value.
     */
    public function up(): void
    {
        if (Schema::hasColumn('system_settings', 'company_id')) {
            return;
        }

        Schema::create('system_settings_scoped', function (Blueprint $table) {
            $table->id();
            $table->string('company_id')->nullable()->index();
            $table->string('key');
            $table->text('value');
            $table->timestamps();
            $table->unique(['company_id', 'key']);
        });

        DB::table('system_settings')->orderBy('key')->chunk(200, function ($rows) {
            DB::table('system_settings_scoped')->insert($rows->map(fn ($row) => [
                'company_id' => null,
                'key' => $row->key,
                'value' => (string) ($row->value ?? ''),
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])->all());
        });

        Schema::drop('system_settings');
        Schema::rename('system_settings_scoped', 'system_settings');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('system_settings', 'company_id')) {
            return;
        }

        Schema::create('system_settings_global', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamps();
        });

        DB::table('system_settings')->whereNull('company_id')->orderBy('key')->chunk(200, function ($rows) {
            DB::table('system_settings_global')->insert($rows->map(fn ($row) => [
                'key' => $row->key,
                'value' => (string) ($row->value ?? ''),
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])->all());
        });

        Schema::drop('system_settings');
        Schema::rename('system_settings_global', 'system_settings');
    }
};
