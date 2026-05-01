<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seed system wallets first — they must exist before any ride/booking data
        $this->call([
            SystemWalletSeeder::class,
        ]);
    }
}
