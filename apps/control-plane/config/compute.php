<?php

declare(strict_types=1);

return [
    /*
     * What suspending a customer's machine does to it at the hypervisor.
     *
     * One of the values SuspensionPolicy declares, and nothing else: an
     * unrecognised string falls back to the strict default rather than
     * silently disabling enforcement, because the failure mode of a typo here
     * is a fleet of unpaid machines that nobody notices are still running.
     *
     *   power_off_and_lock   shut down, clear onboot, and set Proxmox's config
     *                        lock so nothing — including somebody typing
     *                        `qm start` on the node — can bring it back
     *   power_off            shut down and clear onboot, no lock
     *   record_only          change nothing at the provider; the platform's
     *                        own guard is the only thing stopping the customer
     *
     * record_only is what the platform did before Phase 30A. It is named so
     * that a deployment which wants it has to choose it, rather than getting
     * it from an omission nobody noticed.
     */
    'suspension_policy' => env('COMPUTE_SUSPENSION_POLICY', 'power_off_and_lock'),

    /*
     * Placement scoring.
     *
     * Weights rather than hardcoded rules, because different fleets want
     * different trade-offs and a scheduler that needs a deploy to retune is a
     * scheduler nobody retunes.
     */
    'scheduler' => [
        'weights' => [
            // Free memory after this machine lands is the constraint that
            // actually binds on a hypervisor, so it carries the most weight.
            'memory_headroom' => 40,
            'cpu_headroom' => 20,
            'storage_headroom' => 15,
            // Spreading is worth real weight: filling the first node that fits
            // produces one hot node, a fleet of idle ones, and a blast radius
            // that grows with every placement.
            'spread' => 15,
            // Anti-affinity is a correctness concern, not a preference. A
            // customer buying three machines for redundancy who gets three on
            // one node has bought none.
            'anti_affinity' => 10,
        ],

        /*
         * A node is not schedulable past this much of its usable capacity. The
         * remaining headroom is what absorbs a failure elsewhere in the
         * cluster: fill every node to 95% and there is nowhere to evacuate to.
         */
        'capacity_threshold_percent' => (int) env('COMPUTE_CAPACITY_THRESHOLD_PERCENT', 85),

        /*
         * Anti-affinity is enforced as a hard exclusion up to this many of a
         * customer's machines per node, then falls back to scoring. A customer
         * with more machines than the cluster has nodes has to share
         * eventually, and refusing the order would be worse than telling them.
         */
        'max_customer_vms_per_node' => (int) env('COMPUTE_MAX_CUSTOMER_VMS_PER_NODE', 1),
    ],

    /*
     * Reinstalls.
     *
     * A reinstall replaces a machine's disk, which cannot happen while the
     * guest is using it. The guest is asked to shut down and then asked again,
     * harder, until it has — and if it never does, the reinstall is refused
     * before anything is destroyed rather than forced through.
     *
     * Two minutes by default. Long enough for a database to flush and a
     * journal to close; short enough that a customer watching a spinner gets
     * an answer.
     */
    'reinstall' => [
        'stop_poll_attempts' => (int) env('COMPUTE_REINSTALL_STOP_POLL_ATTEMPTS', 60),
        'stop_poll_interval_ms' => (int) env('COMPUTE_REINSTALL_STOP_POLL_INTERVAL_MS', 2000),
    ],

    'proxmox' => [
        'timeout_seconds' => (int) env('PROXMOX_TIMEOUT_SECONDS', 30),
        'verify_tls' => (bool) env('PROXMOX_VERIFY_TLS', true),
        'default_cpu_overcommit_ratio' => (float) env('PROXMOX_NODE_CPU_OVERCOMMIT_RATIO', 4.0),
        'default_memory_headroom_percent' => (int) env('PROXMOX_NODE_MEMORY_HEADROOM_PERCENT', 10),
        // Memory is never overcommitted. A hypervisor that swaps takes every
        // machine on it down together, and the customers who notice first are
        // the ones running the most important workloads.
        'allow_memory_overcommit' => false,
    ],
];
