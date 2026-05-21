<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // status: -1 = banned | 0 = logged out | 1 = active
            // The status column already exists — just add the ban metadata.
            $table->text('ban_reason')->nullable()->after('status');
            $table->enum('ban_type', ['temporary', 'permanent'])->nullable()->after('ban_reason');
            $table->timestamp('banned_at')->nullable()->after('ban_type');
            $table->timestamp('ban_expires_at')->nullable()->after('banned_at');
            $table->unsignedBigInteger('banned_by')->nullable()->after('ban_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ban_reason', 'ban_type', 'banned_at', 'ban_expires_at', 'banned_by']);
        });
    }
};
