<?php

return [
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/'),

    'identity' => [
        'disk' => env('COMPANY_INTERVIEW_IDENTITY_DISK', 'local'),
        'max_document_kb' => (int) env('COMPANY_INTERVIEW_MAX_DOCUMENT_KB', 10240),
        'max_image_kb' => (int) env('COMPANY_INTERVIEW_MAX_IMAGE_KB', 5120),
    ],

    'session' => [
        'heartbeat_timeout_seconds' => (int) env('COMPANY_INTERVIEW_HEARTBEAT_TIMEOUT', 90),
        'snapshot_request_ttl_seconds' => (int) env('COMPANY_INTERVIEW_SNAPSHOT_TTL', 60),
        'prestart_session_minutes' => (int) env('COMPANY_INTERVIEW_PRESTART_SESSION_MINUTES', 180),
    ],

    'liveness_challenges' => [
        'blink_twice',
        'turn_left',
        'turn_right',
        'look_up',
        'look_down',
    ],
];
