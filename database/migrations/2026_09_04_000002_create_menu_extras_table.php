<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_extras', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type'); // bite | softdrink
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('unit')->default('portion');
            $table->string('status')->default('Active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_extras');
    }
};
