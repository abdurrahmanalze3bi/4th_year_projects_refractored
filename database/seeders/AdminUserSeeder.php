<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * AdminUserSeeder
 *
 * Creates the User row that represents the primary admin account.
 * This row is NOT a regular user — it only exists so that:
 *   1. JwtService can issue tokens for admin login.
 *   2. UserObserver::created() can find a valid rater_id when seeding
 *      the 3.0 base rating for every newly created user.
 *
 * Must run BEFORE any other user seeders.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // firstOrCreate: safe to re-run after migrate:fresh --seed
        User::firstOrCreate(
            ['email' => config('admin.system_admin.email')],
            [
                'first_name'          => config('admin.system_admin.first_name', 'Admin'),
                'last_name'           => config('admin.system_admin.last_name',  'User'),
                'password'            => Hash::make(config('admin.system_admin.password')),
                'gender'              => 'M',
                'address'             => 'دمشق',
                'status'              => 1,
                'email_verified_at'   => now(),
                'verification_status' => 'none',
            ]
        );

        $this->command->info('✅  Admin user row ready.');
    }
}
