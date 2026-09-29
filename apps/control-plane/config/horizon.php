<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Horizon
|--------------------------------------------------------------------------
|
| The deployment installs a `lynomia-horizon` systemd unit and this file did
| not exist, so Horizon would have run on its packaged defaults: one supervisor
| watching `default`. This platform's work is on `provisioning` and `payments`,
| and neither would have been consumed by anything. A queue nobody drains is a
| customer whose machine is never built and a payment that is never settled.
|
| Three supervisors rather than one, because the three kinds of work fail
| differently and must not share a retry policy or starve each other.
|
*/

return [

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    /*
     * Namespaced per environment so that a staging Horizon and a production
     * Horizon pointed at one Redis cannot read each other's queues.
     */
    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'lynomia'), '_').'_'.env('APP_ENV', 'local').'_horizon:'
    ),

    'middleware' => ['web'],

    'waits' => [
        // The number an operator is paged on. Provisioning is what a customer
        // is actively waiting for, so its tolerance is the tightest.
        // Keyed `worker connection:queue`, as Horizon keys its process pools.
        'redis-provisioning:provisioning' => 60,
        'redis:payments' => 60,
        'redis-infrastructure:infrastructure' => 900,
        // A minute. A customer told their server is ready two minutes late has
        // been told late, and this is the queue where lateness is visible.
        'redis:notifications' => 60,
        'redis:default' => 180,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 128,

    'defaults' => [
        'supervisor-provisioning' => [
            /*
             * Its own connection because its own clock: redis-provisioning
             * re-reserves a message after 5,760 seconds, sixty past the
             * timeout below. On the shared `redis` connection a build would be
             * handed to a second worker while the first was still cloning
             * (F-08; see config/queue.php).
             */
            'connection' => 'redis-provisioning',
            'queue' => ['provisioning'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 192,
            /*
             * Longer than the longest job the engine will wait for — a
             * dedicated provision is allowed 5,400 seconds — and deliberately
             * not shorter. A worker killed at the supervisor's timeout while a
             * hypervisor is mid-clone leaves a machine nobody has a record of:
             * invisible to billing, to monitoring and to the destroy path. The
             * engine's own per-kind timeout is what decides that a provider has
             * stopped answering; this only has to outlive it.
             *
             * The number is written out rather than derived from
             * provisioning.timeout_seconds, because a configuration file cannot
             * read another one reliably — the first attempt did, silently got
             * the default, and produced a supervisor that would have killed a
             * dedicated build after twenty minutes. The invariant is enforced
             * by HorizonSupervisesEveryQueueTest instead, which fails if the
             * engine's longest timeout ever passes this one.
             *
             * Raising it means raising REDIS_PROVISIONING_RETRY_AFTER with it:
             * a timeout at or past that clock lets one build run twice at once,
             * and App\Queue\QueueRetryClocks refuses to start Horizon when it
             * would.
             */
            'timeout' => (int) env('HORIZON_PROVISIONING_TIMEOUT', 5700),
            /*
             * One attempt. Retrying is the provisioning engine's decision, not
             * the queue's: a job whose provider call may have created a machine
             * must never be re-executed by a worker that cannot know whether it
             * did.
             */
            'tries' => 1,
            'nice' => 0,
        ],

        'supervisor-payments' => [
            'connection' => 'redis',
            'queue' => ['payments'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            // Below the `redis` connection's 180-second clock.
            'timeout' => 120,
            /*
             * The listeners on this queue set their own tries and backoff;
             * this is the ceiling for anything that does not — and it is a
             * real ceiling, not a default of one: a payments class that
             * declares no `tries` runs five times here, with this
             * supervisor's backoff of 0 between attempts unless it declares a
             * ladder. EveryRetriedPaymentsListenerWaitsBetweenAttemptsTest
             * reads it that way.
             */
            'tries' => 5,
            'nice' => 0,
        ],

        'supervisor-infrastructure' => [
            // Its own clock, 1,860 seconds, for the same reason as provisioning.
            'connection' => 'redis-infrastructure',
            'queue' => ['infrastructure'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 192,
            // Reconciliation talks to every node in a cluster in turn; a large
            // estate takes minutes, and being killed halfway means a partial
            // picture rather than none.
            'timeout' => 1800,
            'tries' => 1,
            'nice' => 5,
        ],

        /*
         * Notifications get their own supervisor so that a customer waiting to
         * be told their server is ready never sits behind a fleet-wide
         * reconciliation. The work is short and network-bound — one SMTP
         * conversation — so the timeout is small and the processes are cheap.
         *
         * `tries` is 1 here and the retries live in the job instead. The
         * delivery row is the record of what happened to a message, and a
         * worker retrying underneath it would leave that row's attempt count
         * disagreeing with reality.
         */
        'supervisor-notifications' => [
            'connection' => 'redis',
            'queue' => ['notifications'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'timeout' => 90,
            'tries' => 1,
            'nice' => 0,
        ],

        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'timeout' => 120,
            'tries' => 3,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-provisioning' => [
                'maxProcesses' => (int) env('HORIZON_PROVISIONING_PROCESSES', 6),
                'balanceMaxShift' => 2,
                'balanceCooldown' => 5,
            ],
            'supervisor-payments' => [
                'maxProcesses' => (int) env('HORIZON_PAYMENTS_PROCESSES', 4),
                'balanceMaxShift' => 2,
                'balanceCooldown' => 5,
            ],
            'supervisor-infrastructure' => [
                'maxProcesses' => (int) env('HORIZON_INFRASTRUCTURE_PROCESSES', 2),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
            'supervisor-notifications' => [
                'maxProcesses' => (int) env('HORIZON_NOTIFICATION_PROCESSES', 3),
                'balanceMaxShift' => 2,
                'balanceCooldown' => 5,
            ],
            'supervisor-default' => [
                'maxProcesses' => (int) env('HORIZON_DEFAULT_PROCESSES', 3),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
        ],

        'staging' => [
            'supervisor-provisioning' => ['maxProcesses' => 2],
            'supervisor-payments' => ['maxProcesses' => 2],
            'supervisor-infrastructure' => ['maxProcesses' => 1],
            'supervisor-notifications' => ['maxProcesses' => 1],
            'supervisor-default' => ['maxProcesses' => 1],
        ],

        'local' => [
            'supervisor-provisioning' => ['maxProcesses' => 1],
            'supervisor-payments' => ['maxProcesses' => 1],
            'supervisor-infrastructure' => ['maxProcesses' => 1],
            'supervisor-notifications' => ['maxProcesses' => 1],
            'supervisor-default' => ['maxProcesses' => 1],
        ],
    ],
];
