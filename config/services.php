<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        // Reads equipment labels, receipts, compares vehicle photos and drafts occurrence texts. Empty key: the AI features are off.
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1/messages'),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
        // Requests per day (UTC), per person and for the whole installation, and per person within the last hour.
        'quotas' => [
            'receipt_read' => ['per_user' => (int) env('AI_QUOTA_RECEIPT_USER', 60), 'global' => (int) env('AI_QUOTA_RECEIPT_GLOBAL', 400), 'per_user_hour' => (int) env('AI_QUOTA_RECEIPT_USER_HOUR', 30)],
            'equip_read' => ['per_user' => (int) env('AI_QUOTA_EQUIPMENT_USER', 60), 'global' => (int) env('AI_QUOTA_EQUIPMENT_GLOBAL', 400), 'per_user_hour' => (int) env('AI_QUOTA_EQUIPMENT_USER_HOUR', 30)],
            'vehicle_compare' => ['per_user' => (int) env('AI_QUOTA_VEHICLE_USER', 30), 'global' => (int) env('AI_QUOTA_VEHICLE_GLOBAL', 200), 'per_user_hour' => (int) env('AI_QUOTA_VEHICLE_USER_HOUR', 15)],
            'inventory_identify' => ['per_user' => (int) env('AI_QUOTA_INVENTORY_USER', 30), 'global' => (int) env('AI_QUOTA_INVENTORY_GLOBAL', 150), 'per_user_hour' => (int) env('AI_QUOTA_INVENTORY_USER_HOUR', 15)],
            'occurrence_draft' => ['per_user' => (int) env('AI_QUOTA_OCCURRENCE_USER', 40), 'global' => (int) env('AI_QUOTA_OCCURRENCE_GLOBAL', 200), 'per_user_hour' => (int) env('AI_QUOTA_OCCURRENCE_USER_HOUR', 20)],
        ],
    ],

    'geocoder' => [
        // nominatim (OpenStreetMap, worldwide) or address-fr (French national address API, France only).
        'driver' => env('GEOCODER_DRIVER', 'nominatim'),
        'url' => env('GEOCODER_URL', 'https://nominatim.openstreetmap.org/search'),
        'reverse_url' => env('GEOCODER_REVERSE_URL', 'https://nominatim.openstreetmap.org/reverse'),
        // Countries a town may be in (ISO codes, nominatim only): the ones that have a map.
        'countries' => env('GEOCODER_COUNTRIES', 'fr,pt,es,ua'),
        'timeout' => (int) env('GEOCODER_TIMEOUT', 8),
        // Answers are kept this many days (a town not found: 30 days), and at most this many per cache (oldest dropped).
        'cache_days' => (int) env('GEOCODER_CACHE_DAYS', 180),
        'cache_max_entries' => (int) env('GEOCODER_CACHE_MAX_ENTRIES', 50000),
    ],

];
