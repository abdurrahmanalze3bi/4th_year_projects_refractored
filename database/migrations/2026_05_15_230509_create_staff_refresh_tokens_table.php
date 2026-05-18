<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->boolean('revoked')->default(false);
            $table->string('user_agent', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'revoked']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_refresh_tokens');
    }
};
