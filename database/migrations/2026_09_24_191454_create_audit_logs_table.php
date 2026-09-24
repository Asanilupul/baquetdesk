<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('company_id')->nullable();
            $table->string('user_id')->nullable();
            $table->string('username')->nullable();
            $table->string('role')->nullable();
            $table->string('action', 32);
            $table->string('table_name', 64)->nullable();
            $table->string('record_id')->nullable();
            $table->string('record_label')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
