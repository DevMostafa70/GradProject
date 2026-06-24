<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'guard' => 'candidate',
        'passwords' => 'candidates',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    */
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // Guard للمرشحين (Candidate)
        'candidate' => [
            'driver' => 'sanctum',
            'provider' => 'users',  // ← نفس provider
        ],

        // Guard للشركات (Company)
        'company' => [
            'driver' => 'sanctum',
            'provider' => 'users',  // ← نفس provider
        ],

        // Guard للأدمن (Admin)
        'admin' => [
            'driver' => 'sanctum',
            'provider' => 'users',  // ← نفس provider
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,  // ← model واحد فقط
        ],


    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [
        // للمستخدمين العاديين (إذا احتجتهم مستقبلاً)
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        // للمرشحين
        'candidates' => [
            'driver' => 'eloquent',
            'model' => App\Models\Candidate::class,
        ],

        // للشركات
        'companies' => [
            'driver' => 'eloquent',
            'model' => App\Models\Company::class,
        ],

        // للأدمن
        'admins' => [
            'driver' => 'eloquent',
            'model' => App\Models\Admin::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    */

    'passwords' => [
        'candidates' => [
            'provider' => 'candidates',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
        'companies' => [
            'provider' => 'companies',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
        'admins' => [
            'provider' => 'admins',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
