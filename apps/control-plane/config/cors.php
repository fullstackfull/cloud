<?php

declare(strict_types=1);

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'webhooks/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    /*
     * Never "*". The portals authenticate with cookies, and a wildcard origin
     * is incompatible with credentialed requests for exactly that reason.
     */
    'allowed_origins' => config('security.allowed_origins'),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept', 'Authorization', 'Content-Type', 'X-Requested-With',
        'X-XSRF-TOKEN', 'X-Request-Id', 'Accept-Language',
    ],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 3600,

    'supports_credentials' => true,
];
