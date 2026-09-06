<?php

declare(strict_types=1);

return [
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
