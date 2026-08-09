<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SpecialAccountSeeder::class);
        $this->call(SystemWalletSeeder::class);  // ← ADD THIS
    }
}
