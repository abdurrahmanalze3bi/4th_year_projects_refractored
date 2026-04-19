<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE otps MODIFY type ENUM(
            'E-PAYMENT',
            'WALLET_CREATION',
            'registration',
            'login',
            'password_reset',
            'EMAIL_VERIFICATION'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE otps MODIFY type ENUM(
            'E-PAYMENT',
            'WALLET_CREATION',
            'registration',
            'login',
            'password_reset'
        ) NOT NULL");
    }
};
