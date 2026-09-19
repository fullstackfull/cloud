<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
|
| Which provider takes the money is chosen in config/billing.php
| (`billing.providers.payment`), because that is where the rest of the
| provider selection lives. This file configures the providers themselves.
|
| Nothing here is a secret. Provider credentials are read from the
| environment at the point of use and never written to a file in the
| repository; the keys below are behavioural — currencies, tolerances, and
| which shape of confirmation the fake asks a browser for.
|
*/

return [

    /*
     * The fake provider. It takes no money and reaches no network, and it is
     * refused at boot when APP_ENV=production (see FakeProviderGuard), so a
     * deployment that forgets to configure a real gateway fails loudly rather
     * than pretending to charge customers.
     */
    'fake' => [
        'currencies' => array_values(array_filter(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            explode(',', (string) env('PAYMENTS_FAKE_CURRENCIES', 'KWD,USD,EUR,GBP,SAR,AED')),
        ))),

        /*
         * Signing material for the fake's webhooks. It is a fake secret for a
         * fake provider: the value matters only in that the signature check —
         * the most security-critical branch in the module — runs for real
         * against it in tests.
         */
        'webhook_secret' => env('PAYMENTS_FAKE_WEBHOOK_SECRET', 'fake_webhook_secret'),
        'webhook_tolerance' => (int) env('PAYMENTS_FAKE_WEBHOOK_TOLERANCE', 300),

        /*
         * What the fake asks the browser to do after an intent is created:
         *
         *   redirect             hand the customer to the provider's page,
         *                        which is how a 3-D Secure or KNET flow works;
         *   client_confirmation  keep the customer here and confirm the intent
         *                        with a client credential, which is how a
         *                        card-element flow works.
         *
         * Both paths end the same way — the provider tells the platform,
         * server to server, and only then is the invoice settled.
         */
        'next_action' => env('PAYMENTS_FAKE_NEXT_ACTION', 'redirect'),

        /*
         * Where the fake sends a redirecting browser. The controlled gateway
         * screen lives in the portal, so this points at the frontend; the
         * reference is appended to it.
         */
        'authorise_url' => env('PAYMENTS_FAKE_AUTHORISE_URL'),

        /*
         * A file the fake records approvals and declines in, so that a
         * decision taken in one process is visible to the next one.
         *
         * Without it the fake is a pure function of the reference, which is
         * what the unit tests want. With it, the controlled gateway can
         * approve or decline a specific intent and a later retrieve — a
         * reconciliation run in a queue worker, say — sees that decision.
         * Unset in production, where the fake cannot be selected at all.
         */
        'state_path' => env('PAYMENTS_FAKE_STATE_PATH'),
    ],

];
