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
    /*
     * Days a customer has to pay an issued invoice before it is overdue.
     * Separate from the grace period: the due date is a commercial term shown
     * on the document, while the grace period is how long the service keeps
     * running past it.
     */
    'payment_terms_days' => (int) env('BILLING_PAYMENT_TERMS_DAYS', 7),

    /*
     * Retry schedule for a failed recurring payment, in hours after the
     * previous attempt. An expired card is usually fixed within a day or two,
     * so the early retries are close together and the later ones spread out.
     */
    'payment_retry_hours' => [24, 72, 168],

    /*
     * Providers. A fake provider is refused at boot when APP_ENV=production,
     * so a misconfigured deployment fails loudly rather than pretending to
     * take payments or build servers.
     */
    'providers' => [
        'payment' => env('PAYMENT_PROVIDER', 'fake'),
        'compute' => env('COMPUTE_PROVIDER', 'fake'),
        'dedicated' => env('DEDICATED_PROVIDER', 'fake'),
        'hosting' => env('HOSTING_PROVIDER', 'fake'),
        'dns' => env('DNS_PROVIDER', 'fake'),
        'backup' => env('BACKUP_PROVIDER', 'fake'),
    ],

    /*
     * Overpayment policy. A customer who pays more than the amount due has the
     * surplus credited to their wallet rather than refunded: a wallet credit is
     * instant and costs nothing, while a refund takes days and a provider fee.
     */
    'credit_overpayment_to_wallet' => (bool) env('BILLING_CREDIT_OVERPAYMENT_TO_WALLET', true),

];
