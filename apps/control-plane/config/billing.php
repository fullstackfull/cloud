<?php

declare(strict_types=1);

return [
    /*
     * Currency used when a customer has no explicit preference. Invoices are
     * always issued in the customer's own currency and never silently
     * converted.
     */
    'default_currency' => env('BILLING_DEFAULT_CURRENCY', 'KWD'),

    'invoice_number' => [
        'prefix' => env('BILLING_INVOICE_NUMBER_PREFIX', 'LYN'),
        'padding' => (int) env('BILLING_INVOICE_NUMBER_PADDING', 6),
    ],

    'order_number' => [
        'prefix' => env('BILLING_ORDER_NUMBER_PREFIX', 'ORD'),
        'padding' => (int) env('BILLING_ORDER_NUMBER_PADDING', 6),
    ],

    /*
     * Dunning: how long an unpaid invoice is tolerated before the service is
     * suspended, and how long a suspended service survives before termination.
     */
    'grace_period_days' => (int) env('BILLING_GRACE_PERIOD_DAYS', 7),
    'termination_after_days' => (int) env('BILLING_TERMINATION_AFTER_DAYS', 14),
];
