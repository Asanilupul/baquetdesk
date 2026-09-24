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
        Schema::create('function_types', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->string('status')->default('Active');
            $table->string('company_id')->nullable()->index();
            $table->timestamps();
        });

        $now = now();
        $companyId = DB::table('companies')->value('id');
        $defaults = ['Wedding', 'Homecoming', 'Birthday', 'Engagement', 'Corporate', 'Other'];
        $rows = [];
        foreach ($defaults as $name) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'name' => $name,
                'notes' => null,
                'status' => 'Active',
                'company_id' => $companyId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('function_types')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('function_types');
    }
};
