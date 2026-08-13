<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master Toggle
    |--------------------------------------------------------------------------
    | Set RATE_LIMIT_ENABLED=false in .env to disable all throttling globally.
    | Useful for load testing. Set to true in production always.
    */
    'enabled' => env('RATE_LIMIT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Per-Category Limits (requests per minute)
    |--------------------------------------------------------------------------
    | Each can be overridden individually in .env without touching code.
    |
    | Real-world reference:
    |   auth    →  5  (OTP/login brute-force protection)
    |   api     →  60  (standard user actions)
    |   search  →  30  (heavier DB queries, don't hammer)
    |   uploads →  10  (bandwidth + storage cost)
    |   admin   →  300 (trusted role, higher throughput)
    |   staff   →  200 (trusted role, moderate throughput)
    */
    'limits' => [
        'auth'    => (int) env('RATE_LIMIT_AUTH',    5),
        'api'     => (int) env('RATE_LIMIT_API',     60),
        'search'  => (int) env('RATE_LIMIT_SEARCH',  30),
        'uploads' => (int) env('RATE_LIMIT_UPLOADS', 10),
        'admin'   => (int) env('RATE_LIMIT_ADMIN',   300),
        'staff'   => (int) env('RATE_LIMIT_STAFF',   200),
    ],

];
