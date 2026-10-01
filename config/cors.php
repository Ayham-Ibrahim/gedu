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

    // Local development only: any port on localhost / 127.0.0.1 (Vite uses
    // 5173 by default, the Express dev server 3000, etc.). Production is
    // restricted to the exact FRONTEND_URL origins above.
    'allowed_origins_patterns' => env('APP_ENV') === 'local'
        ? ['#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#']
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
