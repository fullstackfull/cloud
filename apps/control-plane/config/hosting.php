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
];
