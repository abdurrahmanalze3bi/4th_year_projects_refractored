<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ORDER IS CRITICAL:
        //
        // 1. AdminUserSeeder  — creates the admin User row FIRST.
        //    Atarikaktestseeder::createDrivers() does:
        //      $adminUser = User::where('email', config('admin.primary.email'))->first();
        //    If admin doesn't exist yet → $adminUser is null → no 3.0 rating seeded for anyone.
        //
        // 2. SystemWalletSeeder — creates Primary Escrow + SyCash wallets.
        //    Atarikaktestseeder checks for these at startup and aborts if missing.
        //
        // 3. Atarikaktestseeder — creates all users (drivers + passengers + unverified)
        //    AND runs every business scenario (completed rides, cancellations, no-shows…).
        //    Do NOT also call DriverSeeder/PassengerSeeder — they would create duplicate users.

        $this->call([
            AdminUserSeeder::class,     // admin User row (JWT principal + system rater)
            SystemWalletSeeder::class,  // Primary Escrow + SyCash wallets
            Atarikaktestseeder::class,  // all users + all ride scenarios
        ]);
    }
}
