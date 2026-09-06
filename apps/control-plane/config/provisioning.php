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
