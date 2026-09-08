<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Ceilings
    |--------------------------------------------------------------------------
    |
    | Neither of these is a licensing decision; they are the point at which a
    | zone stops being a zone and starts being a storage product. A customer
    | who genuinely needs more asks, and an operator raises it for them, which
    | is a conversation rather than a surprise.
    |
    */

    'zones_per_customer' => (int) env('DNS_ZONES_PER_CUSTOMER', 50),

    'records_per_zone' => (int) env('DNS_RECORDS_PER_ZONE', 250),

    /*
    |--------------------------------------------------------------------------
    | Zones no account may claim
    |--------------------------------------------------------------------------
    |
    | The platform's own names. A customer who could hold the zone the control
    | plane answers on could point it wherever they liked, and every customer
    | on the platform would follow them there.
    |
    | A name here also protects everything beneath it: claiming a parent of a
    | reserved zone is the same attack one step out.
    |
    */

    'reserved_zones' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DNS_RESERVED_ZONES', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | How stale the platform's picture of a zone may be before the sweep looks
    | at it again, and how many zones one run will look at. The second exists
    | because a provider's API has a rate limit and a sweep that hits it does
    | not reconcile anything at all.
    |
    */

    'reconcile_after_hours' => (int) env('DNS_RECONCILE_AFTER_HOURS', 6),

    'reconcile_batch' => (int) env('DNS_RECONCILE_BATCH', 50),

];
