<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    */

 'defaults' => [
    'guard' => 'web',  // بدلاً من 'candidate'
    'passwords' => 'users',
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
            'provider' => 'candidates', // ✅ تم التعديل: استخدام provider مخصص للمرشحين
        ],

        // Guard للشركات (Company)
        'company' => [
            'driver' => 'sanctum',
            'provider' => 'companies', // ✅ تم التعديل: استخدام provider مخصص للشركات
        ],

        // Guard للأدمن (Admin)
        'admin' => [
            'driver' => 'sanctum',
            'provider' => 'admins', // ✅ تم التعديل: استخدام provider مخصص للأدمن
        ],

        // Guard للمستخدمين العاديين (Regular User)
        'user' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [
        // للمستخدمين العاديين
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        // للمرشحين (Candidate) - يستخدمون جدول candidates
        'candidates' => [
            'driver' => 'eloquent',
            'model' => App\Models\Candidate::class,
        ],

        // للشركات (Company) - يستخدمون جدول companies
        'companies' => [
            'driver' => 'eloquent',
            'model' => App\Models\Company::class,
        ],

        // للأدمن (Admin) - يستخدمون جدول admins
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
