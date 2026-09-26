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
    | A name here is refused, and so is every parent of it and everything
    | beneath it: claiming a parent is the same attack one step out, and a
    | child is a piece of the platform's name space held by somebody else.
    |
    | This list is not the whole reservation. The hosts of APP_URL and
    | FRONTEND_URL are added to it when they are domain names — a Unicode host
    | in the xn-- form DNS carries — so an estate that has put the platform on
    | its real name is covered without repeating it here. List what those two
    | do not name — the registrable domain above them, above all, which covers
    | every sibling as well. On the shipped localhost they name nothing.
    |
    | Entries are ASCII: an internationalised name goes in its xn-- form. An
    | entry that is not a domain name, or not a string at all, refuses every
    | claim until it is corrected. The estate preflight reports it as
    | `dns.reserved_zones`, and reports an empty reservation there too, with
    | the reason each address gave no name.
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

    /*
     * Where the controlled DNS simulator keeps what it remembers, so that
     * more than one process can see the same thing.
     *
     * Unset by default, and unset everywhere but the tests that need it: an
     * in-memory simulator is the right one inside a single process, and a
     * shared file would let one test's zones leak into the next test's.
     * It exists because a workflow in this platform crosses a request, a queue
     * and a worker, and a simulator that forgets at the process boundary can
     * take part in a contract test but not in a workflow.
     *
     * @see \Lynomia\Modules\Shared\Infrastructure\Simulation\ControlledSimulationStore
     */
    'fake' => [
        'state_path' => env('DNS_FAKE_STATE_PATH'),
    ],

    /*
     * And the reverse-DNS simulator's own file, separate from the forward one.
     *
     * Two files rather than one keyed map, because the two simulators are two
     * classes in two modules with two contracts, and a single file would make
     * a test that resets one reset the other.
     */
    'fake_reverse' => [
        'state_path' => env('DNS_FAKE_REVERSE_STATE_PATH'),
    ],
];
