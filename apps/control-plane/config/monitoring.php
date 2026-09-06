<?php

declare(strict_types=1);

return [
    /*
     * The metrics endpoint is never public.
     *
     * These figures disclose customer counts, capacity headroom and revenue —
     * a competitor's market research and an attacker's capacity planning in one
     * request. The token is compared with hash_equals, and an unset token
     * disables the endpoint rather than leaving it open.
     */
    'metrics' => [
        'enabled' => (bool) env('METRICS_ENABLED', true),
        'token' => env('PROMETHEUS_METRICS_TOKEN'),
        'path' => env('METRICS_PATH', 'metrics'),

        /*
         * Collection has to be cheap. A metrics endpoint that takes ten seconds
         * gets scraped every fifteen and becomes the outage it was installed to
         * detect.
         */
        'cache_seconds' => (int) env('METRICS_CACHE_SECONDS', 10),
    ],
];
