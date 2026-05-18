<?php

return [
    'system_admin' => [
        'email'    => env('ADMIN_PRIMARY_EMAIL',    'system_admin@admin.com'),
        'username' => env('ADMIN_PRIMARY_USERNAME', 'admin'),
        'password' => env('ADMIN_PRIMARY_PASSWORD', 'admin'),// ✅ Added default
        'phone' => env('ADMIN_PRIMARY_PHONE', '0912345678'),
        'first_name' => 'Admin',
        'last_name' => 'User',
        'wallet_prefix' => 'ADM',
        'permissions' => ['*'],
    ],

    'sycash' => [
        'email'    => env('ADMIN_SYCASH_EMAIL',    'sycash@admin.com'),
        'username' => env('ADMIN_SYCASH_USERNAME', 'sycash'),   // ← add this line
        'password' => env('ADMIN_SYCASH_PASSWORD', 'sycash123'),
        'phone' => env('ADMIN_SYCASH_PHONE', '0987654321'),
        'first_name' => 'SyCash',
        'last_name' => 'Admin',
        'wallet_prefix' => 'SYC',
        'permissions' => ['wallet.view', 'wallet.charge'],
    ],

    'session' => [
        'lifetime' => 120,
        'driver' => 'database',
    ],

    'security' => [
        'max_login_attempts' => 3,
        'lockout_duration' => 15,
        'require_2fa' => false,
    ],
];
