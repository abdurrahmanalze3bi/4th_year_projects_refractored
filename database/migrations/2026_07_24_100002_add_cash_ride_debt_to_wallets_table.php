<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            if (! Schema::hasColumn('wallets', 'cash_ride_debt')) {
                $table->decimal('cash_ride_debt', 15, 2)->default(0)->after('balance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            if (Schema::hasColumn('wallets', 'cash_ride_debt')) {
                $table->dropColumn('cash_ride_debt');
            }
        });
    }
};
