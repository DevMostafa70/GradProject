<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key and Organization
    |--------------------------------------------------------------------------
    */
    'api_key' => env('OPENAI_API_KEY'),
    // 'organization' => env('OPENAI_ORGANIZATION'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    */
    'request_timeout' => env('OPENAI_REQUEST_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Whisper Configuration
    |--------------------------------------------------------------------------
    */
    'whisper' => [
        'default_model' => env('WHISPER_MODEL', 'whisper-1'),
        'language' => env('WHISPER_LANGUAGE', 'en'),
        'response_format' => 'verbose_json',
        'temperature' => 0.2,
        'max_file_size' => 25 * 1024 * 1024, // 25MB in bytes
    ],

    /*
    |--------------------------------------------------------------------------
    | GPT Configuration
    |--------------------------------------------------------------------------
    */
    'gpt' => [
        'default_model' => env('OPENAI_MODEL', 'gpt-4'),
        'fallback_model' => 'gpt-3.5-turbo',
        'max_tokens' => 2000,
        'temperature' => 0.7,
    ],
];
