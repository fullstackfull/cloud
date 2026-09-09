<?php

declare(strict_types=1);

return [
    /*
     * Placement thresholds for shared hosting nodes.
     *
     * Disk is deliberately the tightest. A shared node that fills up does not
     * degrade — it stops accepting mail and breaks every site on it at the same
     * moment, and the customers who notice first are the ones who have been
     * there longest and have the most to lose.
     */
    'scheduler' => [
        'max_disk_used_percent' => (int) env('HOSTING_MAX_DISK_PERCENT', 75),
        'max_load_average' => (float) env('HOSTING_MAX_LOAD_AVERAGE', 8.0),
        // A ceiling per node regardless of disk, because account density drives
        // support load and noisy-neighbour complaints long before it drives
        // disk usage.
        'max_accounts_per_node' => (int) env('HOSTING_MAX_ACCOUNTS_PER_NODE', 250),

        'weights' => [
            'disk_headroom' => 50,
            'account_headroom' => 30,
            'load_headroom' => 20,
        ],
    ],

    /*
     * How long a suspended account is kept before termination releases its
     * data.
     *
     * Suspension is reversible and preserves everything, because most
     * suspensions are billing disputes that end with the customer paying. A
     * suspension that destroyed data would turn a late invoice into a lost
     * customer and a liability.
     */
    'retention' => [
        'suspended_days' => (int) env('HOSTING_SUSPENDED_RETENTION_DAYS', 30),
    ],

    /*
     * Licence enforcement.
     *
     * cPanel/WHM, DirectAdmin, CloudLinux and LiteSpeed are commercial
     * products. The platform never bypasses, patches or works around their
     * licensing, and there is no flag here that enables such a thing. A node
     * without a valid licence simply cannot take accounts — discovering that at
     * provisioning time means a customer has already paid.
     */
    'licence' => [
        'recheck_hours' => (int) env('HOSTING_LICENCE_RECHECK_HOURS', 12),
        'block_provisioning_when_unlicensed' => true,
    ],

    'timeout_seconds' => (int) env('HOSTING_TIMEOUT_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | How many nodes one run compares with its panel. Bounded because each is
    | a full account listing over an API with its own rate limit, and a sweep
    | that exhausts it reconciles nothing at all.
    |
    | Nothing it finds is ever repaired automatically. See
    | ReconcileHostingNodes for why that is a decision rather than an omission.
    |
    */

    'reconcile_batch' => (int) env('HOSTING_RECONCILE_BATCH', 25),

    /*
    |--------------------------------------------------------------------------
    | WordPress
    |--------------------------------------------------------------------------
    |
    | `probe` decides who fetches a customer's site to check it answers. The
    | HTTP one is the only thing on this platform that makes a request to an
    | address a customer chose, and it refuses names that resolve anywhere
    | private — see HttpSiteProbe for why that is three separate defences
    | rather than one.
    |
    | The fake answers from markers in the name and never touches the network,
    | which is what lets the browser and feature suites rehearse a site that is
    | down, a site that is somebody else's, and a site with no certificate.
    |
    */

    'wordpress' => [
        'probe' => env('HOSTING_SITE_PROBE', 'http'),

        // How many sites one verification pass fetches. Bounded because each
        // is an outbound request with a five-second ceiling, and a sweep that
        // ran for an hour would overlap itself.
        'verify_batch' => (int) env('HOSTING_VERIFY_BATCH', 200),
    ],

];
