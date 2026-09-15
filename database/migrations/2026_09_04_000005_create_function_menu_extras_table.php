<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('function_menu_extras', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('function_id');
            $table->string('extra_id');
            $table->decimal('quantity', 14, 2)->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->timestamps();
            $table->unique(['function_id', 'extra_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('function_menu_extras');
    }
};
