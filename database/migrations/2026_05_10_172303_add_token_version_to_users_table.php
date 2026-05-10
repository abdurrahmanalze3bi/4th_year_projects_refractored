<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generate this file via:
 *   php artisan make:migration add_token_version_to_users_table --table=users
 *
 * token_version is incremented every time the user changes their password
 * or explicitly logs out from all devices. This invalidates ALL previously
 * issued access tokens immediately (since JwtAuthMiddleware checks it on
 * every request — no extra DB query, user row is already loaded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('token_version')
                ->default(1)
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('token_version');
        });
    }
};
