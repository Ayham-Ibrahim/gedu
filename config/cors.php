<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel CORS Configuration
    |--------------------------------------------------------------------------
    |
    | Allow the React Vite frontend to call the Laravel API.
    | Update FRONTEND_URL in .env to your actual production domain.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // FRONTEND_URL may hold several comma-separated origins, e.g.
    // FRONTEND_URL=https://gedulink.com,https://www.gedulink.com
    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        array_map('trim', explode(',', (string) env('FRONTEND_URL', ''))),
        [
            'http://localhost:3000',
            'http://localhost:4173',   // Vite preview
        ],
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
