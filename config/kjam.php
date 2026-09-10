<?php

return [
    /*
    |--------------------------------------------------------------------------
    | KJA Application Settings
    |--------------------------------------------------------------------------
    |
    | Application metadata and project-level settings.
    | Keep values simple and backward compatible.
    |
    */

    'name' => env('KJAM_NAME', 'AplikasiLomba'),
    'short_name' => env('KJAM_SHORT_NAME', 'AplikasiLomba'),
    'mvp_name' => env('KJAM_MVP_NAME', 'CAI Operational'),
    'default_timezone' => env('KJAM_TIMEZONE', 'Asia/Jakarta'),
    'support_email' => env('KJAM_SUPPORT_EMAIL', 'support@example.com'),

    /*
    |--------------------------------------------------------------------------
    | Event & Logo Configuration
    |--------------------------------------------------------------------------
    |
    | Configurable per-event branding used in printed documents.
    | Logo paths are relative to public/ directory.
    | Safe fallback when logo file does not exist.
    |
    */

    'event_name' => env('KJAM_EVENT_NAME', 'CAI'),
    'event_logo' => env('KJAM_EVENT_LOGO', 'images/logo-cai.png'),
    'org_logo' => env('KJAM_ORG_LOGO', 'images/logo-org.png'),
];
