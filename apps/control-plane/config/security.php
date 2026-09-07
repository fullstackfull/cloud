<?php

declare(strict_types=1);

return [
    /*
     * Enables HSTS and secure cookie flags. Must be true in production; the
     * production readiness check fails the deployment when it is not.
     */
    'force_https' => (bool) env('FORCE_HTTPS', false),

    /*
     * Origins allowed to call the API with credentials. Never "*", because the
     * API uses cookie authentication for the portals.
     *
     * "*" is dropped rather than passed through, because the CORS layer does
     * NOT reject it the way the sentence above assumes: with
     * supports_credentials the wildcard falls through to origin reflection and
     * echoes every requesting origin with Access-Control-Allow-Credentials:
     * true. Dropping it leaves no allowed origin at all, so the portal breaks
     * loudly in staging instead of shipping a credentialed open CORS policy.
     */
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))),
        static fn (string $origin): bool => $origin !== '' && $origin !== '*',
    )),

    /*
     * Keys whose values are replaced with "[redacted]" before anything is
     * written to a log, a job payload dump or a stored provider response.
     */
    'redacted_keys' => [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'secret', 'token', 'access_token', 'refresh_token', 'api_key', 'apikey',
        'authorization', 'auth', 'credentials', 'private_key', 'privatekey',
        'two_factor_secret', 'two_factor_recovery_codes', 'recovery_code',
        'ticket', 'csrfpreventiontoken',
        'stripe_secret', 'webhook_secret', 'client_secret',
        'bmc_password', 'ipmi_password', 'ilo_password', 'root_password',
        'card', 'card_number', 'cvv', 'cvc', 'pan',
    ],

    'rate_limits' => [
        // Authentication is the most attacked surface; keep it tight.
        'login' => ['attempts' => 5, 'decay_minutes' => 1],
        'register' => ['attempts' => 5, 'decay_minutes' => 10],
        'password_reset' => ['attempts' => 3, 'decay_minutes' => 10],
        'two_factor' => ['attempts' => 5, 'decay_minutes' => 1],

        // Default ceiling for authenticated API tokens, overridable per token.
        'api_token' => ['attempts' => 120, 'decay_minutes' => 1],

        // Provisioning actions are expensive and irreversible; throttle harder.
        'provisioning' => ['attempts' => 20, 'decay_minutes' => 1],
    ],
];
