<?php

declare(strict_types=1);

return [
    /*
     * Currency used when a customer has no explicit preference. Invoices are
     * always issued in the customer's own currency and never silently
     * converted.
     */
    'default_currency' => env('BILLING_DEFAULT_CURRENCY', 'KWD'),

    /*
     * Currencies this platform is willing to bill in.
     *
     * A currency is enabled here only when the payment provider can take it
     * and the catalogue prices in it; the list is what registration offers and
     * what every currency-bearing request is validated against, so a browser
     * that rewrites the select in DevTools submits a currency the server does
     * not accept and the registration is refused rather than quietly booked in
     * something else.
     */
    'currencies' => array_values(array_filter(array_map(
        static fn (string $code): string => strtoupper(trim($code)),
        explode(',', (string) env('BILLING_CURRENCIES', 'KWD,USD,EUR,GBP,SAR,AED')),
    ))),

    /*
     * Country to recommended currency, stated explicitly.
     *
     * This is the whole of the mapping: there is no algorithm behind it, no
     * locale guess, and nothing in the browser that derives a currency from a
     * country. The portal shows what this table says before the customer
     * submits, the customer can choose any enabled currency instead, and a
     * country with no entry gets the '*' row — a recommendation the screen
     * names out loud rather than a default nobody was told about.
     *
     * Every value must be in 'currencies' above; the registration options
     * endpoint asserts that, so a half-finished edit here fails a test rather
     * than offering a currency the platform cannot charge.
     */
    'country_currencies' => [
        // The Gulf, where this platform sells and prices directly.
        'KW' => 'KWD',
        'SA' => 'SAR',
        'AE' => 'AED',

        // Sterling.
        'GB' => 'GBP', 'GG' => 'GBP', 'IM' => 'GBP', 'JE' => 'GBP',

        // The euro area and the territories that use the euro.
        'AD' => 'EUR', 'AT' => 'EUR', 'AX' => 'EUR', 'BE' => 'EUR', 'BL' => 'EUR', 'CY' => 'EUR',
        'DE' => 'EUR', 'EE' => 'EUR', 'ES' => 'EUR', 'FI' => 'EUR', 'FR' => 'EUR', 'GF' => 'EUR',
        'GP' => 'EUR', 'GR' => 'EUR', 'HR' => 'EUR', 'IE' => 'EUR', 'IT' => 'EUR', 'LT' => 'EUR',
        'LU' => 'EUR', 'LV' => 'EUR', 'MC' => 'EUR', 'ME' => 'EUR', 'MF' => 'EUR', 'MQ' => 'EUR',
        'MT' => 'EUR', 'NL' => 'EUR', 'PM' => 'EUR', 'PT' => 'EUR', 'RE' => 'EUR', 'SI' => 'EUR',
        'SK' => 'EUR', 'SM' => 'EUR', 'VA' => 'EUR', 'YT' => 'EUR',

        // The US dollar, including the territories that use it as legal tender.
        'US' => 'USD', 'AS' => 'USD', 'BQ' => 'USD', 'EC' => 'USD', 'FM' => 'USD', 'GU' => 'USD',
        'MH' => 'USD', 'MP' => 'USD', 'PA' => 'USD', 'PR' => 'USD', 'PW' => 'USD', 'SV' => 'USD',
        'TC' => 'USD', 'TL' => 'USD', 'UM' => 'USD', 'VG' => 'USD', 'VI' => 'USD',

        /*
         * Everywhere else. The platform does not price in every currency on
         * earth, so a customer outside the rows above is billed in US dollars
         * — and is told so, in the currency field, before they submit.
         */
        '*' => 'USD',
    ],

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
