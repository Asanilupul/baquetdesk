<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('halls', function (Blueprint $table) {
            $table->decimal('hall_only_price', 12, 2)->nullable()->after('capacity');
        });

        Schema::create('hall_inclusions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('status')->default('Active');
            $table->string('company_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('functions', function (Blueprint $table) {
            $table->json('hall_inclusions')->nullable()->after('optional_vendor_extras');
        });
    }

    public function down(): void
    {
        Schema::table('functions', function (Blueprint $table) {
            $table->dropColumn('hall_inclusions');
        });

        Schema::dropIfExists('hall_inclusions');

        Schema::table('halls', function (Blueprint $table) {
            $table->dropColumn('hall_only_price');
        });
    }
};
