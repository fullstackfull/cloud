<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Quotes
    |--------------------------------------------------------------------------
    |
    | How long the platform stands behind a price it showed a customer.
    |
    | Short on purpose. A quote is a promise about a namespace anybody in the
    | world may buy from at any moment, and the longer it lives the more often
    | the promise is one the registry will not honour — the customer reaches
    | checkout, the registrar says the name is gone, and the money has already
    | moved. Fifteen minutes is long enough to fill in a contact form and short
    | enough that the answer is still roughly true.
    |
    */

    /*
     * How long a name stays claimed for an order nobody paid for.
     *
     * A registration claims the name here before the invoice is paid, so two
     * customers cannot buy it in the same minute. Without an expiry on that
     * claim, one unpaid order holds a name against everybody for ever.
     *
     * Generous, because the failure it guards against is a customer whose bank
     * took a day. Not indefinite, because the cost of that is a name nobody
     * can ever buy.
     */
    'abandoned_order_days' => (int) env('DOMAINS_ABANDONED_ORDER_DAYS', 7),

    'quote_ttl_minutes' => (int) env('DOMAIN_QUOTE_TTL_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | Availability lookups cost money and are rate-limited by every registrar
    | worth using, so the platform caches an answer briefly and refuses to ask
    | the same question twice in a row.
    |
    | The cache is keyed by name only and never by customer: an availability
    | answer is a fact about the world rather than about the person asking, and
    | keying it per customer would multiply the calls by the number of people
    | searching. What must never be shared is the *history* — who searched for
    | what — and nothing here stores that.
    |
    */

    'search' => [
        'cache_seconds' => (int) env('DOMAIN_SEARCH_CACHE_SECONDS', 60),

        // How many alternative endings a single search may check. Each one is
        // a provider call; a search box that fanned out across fifty
        // namespaces would be a rate limit incident with every keystroke.
        'max_suggestions' => (int) env('DOMAIN_SEARCH_MAX_SUGGESTIONS', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Renewal
    |--------------------------------------------------------------------------
    |
    | When the platform starts trying to renew, and how loudly it says so.
    |
    | Configuration rather than a constant in a state machine, because this is
    | commercial policy: a registrar that bills in advance and one that bills
    | on the day want different lead times, and a business may want to warn
    | earlier than it charges.
    |
    */

    'renewal' => [
        // How many days before expiry an auto-renewal is attempted.
        'lead_days' => (int) env('DOMAIN_RENEWAL_LEAD_DAYS', 30),

        // When to tell the customer it is coming, so that somebody who wants
        // to cancel has time to.
        'warn_days' => (int) env('DOMAIN_RENEWAL_WARN_DAYS', 45),
    ],

    /*
    |--------------------------------------------------------------------------
    | The fake registrar
    |--------------------------------------------------------------------------
    |
    | Development and tests only; the production guard refuses to construct it
    | anywhere else.
    |
    */

    'fake' => [
        /*
         * A file the portfolio is kept in, so more than one process can see
         * it. Null keeps it in memory, which is right for a single process and
         * wrong for the runtime proofs, where a queue worker in another
         * process has to renew what a web request registered.
         */
        'state_path' => env('DOMAINS_FAKE_STATE_PATH'),

        /*
         * The namespaces the fake will answer for. Development data, and
         * marked as such: nothing here is a commercial commitment, and the
         * catalogue rows in the database are what actually decides what is on
         * sale.
         */
        'tlds' => ['com', 'net', 'org', 'test'],
    ],

];
