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

    // ============================================================
    // 🔹 NEW: Audio Privacy Settings
    // ============================================================
    /*
    |--------------------------------------------------------------------------
    | Audio Privacy Settings
    |--------------------------------------------------------------------------
    |
    | These settings control how long audio files are retained and when they
    | should be automatically deleted to protect user privacy.
    |
    */

    'audio' => [
        /*
         * Number of days to retain audio files after processing.
         * Set to 0 to delete immediately after processing.
         * Set to null to never delete automatically.
         * Default: 7 days
         */
        'retention_days' => env('AUDIO_RETENTION_DAYS', 7),

        /*
         * Storage disk used for audio files
         * Default: public
         */
        'storage_disk' => env('AUDIO_STORAGE_DISK', 'public'),

        /*
         * Path prefix for audio files
         * Default: answers
         */
        'storage_path' => env('AUDIO_STORAGE_PATH', 'answers'),

        /*
         * Whether to delete audio files immediately after processing
         * If true, retention_days is ignored
         */
        'delete_after_processing' => env('AUDIO_DELETE_AFTER_PROCESSING', false),

        /*
         * Enable audio cleanup scheduled job
         * Default: true
         */
        'enable_cleanup_schedule' => env('AUDIO_ENABLE_CLEANUP_SCHEDULE', true),

        /*
         * Time of day to run audio cleanup (24-hour format)
         * Default: 02:00 (2 AM)
         */
        'cleanup_schedule_time' => env('AUDIO_CLEANUP_SCHEDULE_TIME', '02:00'),
    ],
];
