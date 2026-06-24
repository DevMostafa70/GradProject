<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | This URL is used when the backend needs to redirect the company back
    | to the React application, especially after Stripe Checkout.
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /*
    |--------------------------------------------------------------------------
    | Company Statuses
    |--------------------------------------------------------------------------
    |
    | These values must stay aligned with the current companies.status column.
    |
    */

    'company_statuses' => [
        'pending' => 'pending',
        'approved' => 'approved',
        'suspended' => 'suspended',
        'rejected' => 'rejected',
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing Statuses
    |--------------------------------------------------------------------------
    |
    | These are local business-level billing states. Stripe will remain the
    | payment source of truth, but these values help the application make
    | fast authorization decisions.
    |
    */

    'billing_statuses' => [
        'none' => 'none',
        'checkout_pending' => 'checkout_pending',
        'active' => 'active',
        'trialing' => 'trialing',
        'past_due' => 'past_due',
        'restricted' => 'restricted',
        'cancelled' => 'cancelled',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Billing Grace Period
    |--------------------------------------------------------------------------
    |
    | Number of days a company may keep limited access after a payment failure.
    |
    */

    'billing_grace_days' => env('BILLING_GRACE_DAYS', 3),
];
