<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decouple system wallets from user accounts.
 *
 * - wallets.user_id             → nullable (system wallets have no owner)
 * - wallets.name                → new column for system wallet identity
 * - wallet_transactions.user_id → nullable (transactions against system wallets)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            // Make user_id optional so system wallets need no owner
            $table->unsignedBigInteger('user_id')->nullable()->change();

            // Human-readable label: 'Primary Escrow', 'SyCash', or null for user wallets
            $table->string('name')->nullable()->after('id');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
