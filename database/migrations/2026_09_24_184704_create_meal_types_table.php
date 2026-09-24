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
        Schema::create('meal_types', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('Active');
            $table->string('company_id')->nullable()->index();
            $table->timestamps();
        });

        $now = now();
        $companyIds = DB::table('companies')->pluck('id')->all() ?: [null];
        $defaults = [
            ['name' => 'Breakfast', 'start_time' => null, 'end_time' => null],
            ['name' => 'Lunch', 'start_time' => '09:30', 'end_time' => '15:30'],
            ['name' => 'Dinner', 'start_time' => null, 'end_time' => null],
        ];
        $rows = [];
        foreach ($companyIds as $companyId) {
            foreach ($defaults as $default) {
                $rows[] = $default + [
                    'id' => (string) Str::uuid(),
                    'notes' => null,
                    'status' => 'Active',
                    'company_id' => $companyId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('meal_types')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_types');
    }
};
