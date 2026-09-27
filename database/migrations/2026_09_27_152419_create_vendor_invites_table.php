<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_invites', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->string('note')->nullable();
            $table->string('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->string('vendor_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_invites');
    }
};
