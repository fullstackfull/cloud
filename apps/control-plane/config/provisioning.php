<?php

declare(strict_types=1);

return [
    /*
     * How long a provisioning operation may run before the platform stops
     * waiting. Stopping waiting is NOT the same as the operation failing —
     * see the retry policy below.
     */
    'timeout_seconds' => [
        'default' => (int) env('PROVISIONING_TIMEOUT_SECONDS', 900),
        'create_vps' => 900,
        'destroy_vps' => 300,
        'reinstall' => 1800,
        'provision_dedicated' => 5400,
        'create_hosting_account' => 300,
    ],

    'retry' => [
        'max_attempts' => (int) env('PROVISIONING_MAX_ATTEMPTS', 3),
        // Seconds between attempts. A hypervisor briefly refusing connections
        // recovers in seconds; one that is genuinely full does not, which is
        // why the later waits are long enough for an operator to intervene.
        'backoff_seconds' => [30, 120, 600],

        /*
         * Which failure classes may be retried automatically.
         *
         * `timeout` is deliberately absent. A timeout means the platform
         * stopped waiting, not that the provider stopped working — the
         * resource may well exist. Retrying is how a customer ends up with two
         * servers and the provider with one that is unbilled and unmanaged.
         * A timed-out job goes to an operator, who checks for an orphan first.
         */
        'retryable_classes' => ['transient', 'capacity'],
    ],

    /*
     * A reservation outlives its job by this much before the reaper will even
     * consider it. The reaper still refuses to release a reservation whose job
     * is running — this only bounds how long a terminal job's resources stay
     * held.
     */
    'reservation_ttl_seconds' => (int) env('PROVISIONING_RESERVATION_TTL', 3600),

    /*
     * How long a suspended service is kept before a termination may destroy
     * what is on it.
     *
     * The same rule shared hosting has, and for the same reason: most
     * suspensions are billing disputes that end with the customer paying, and
     * a suspension that destroyed data would turn a late invoice into a lost
     * customer and a liability. An operator acting on an explicit request — an
     * abuse case, or somebody asking for their data to be deleted now — can
     * override it, and that override is recorded.
     */
    'termination' => [
        'suspended_retention_days' => (int) env('PROVISIONING_SUSPENDED_RETENTION_DAYS', 30),

        /*
         * How long before the data goes the customer is told it is going.
         *
         * A month is long enough to forget a cancellation made in a hurry, and
         * "your data is destroyed on Friday" is the message that has saved
         * somebody's business more than once. Sent once per service; the
         * column that records it is what makes that true.
         */
        'warn_days_before' => (int) env('PROVISIONING_RETENTION_WARN_DAYS', 3),

        /*
         * Whether the sweep may end services on its own.
         *
         * True by default and only for services a customer cancelled: they
         * chose the date and were told it twice. A service suspended for
         * non-payment is never ended automatically whatever this says — that
         * is a decision with a person's name on it.
         */
        'sweep_cancelled' => (bool) env('PROVISIONING_SWEEP_CANCELLED', true),
    ],

    'reconciliation' => [
        'interval_minutes' => (int) env('PROVISIONING_RECONCILE_MINUTES', 30),
        /*
         * Drift is never auto-healed. Every automated remedy is one bug away
         * from deleting production, so the platform records, alerts, and waits
         * for a human. This flag exists to make that a stated decision rather
         * than an omission.
         */
        'auto_heal' => false,
    ],
];
