<?php

declare(strict_types=1);

return [
    /*
     * The one switch that replaces every BMC adapter with the controlled fake.
     *
     * Read here since Wave 0. DEDICATED_PROVIDER had been documented in
     * .env.example and set in phpunit.xml for a year without anything
     * consuming it: the factory read `dedicated.provider`, this file never
     * declared it, and every environment that was not a PHPUnit test (which
     * sets the config key by hand) built a real Redfish or IPMI adapter and
     * tried to open a connection to 192.0.2.x. The browser suite found it the
     * first time it pressed Power cycle. The fake refuses to be constructed in
     * production whatever this says, so a production .env carrying "fake" is
     * a loud failure rather than a silent one.
     */
    'provider' => env('DEDICATED_PROVIDER'),

    'bmc' => [
        // Both timeouts bound how long a power request can honestly hold its
        // claim, and `power.claim_lease_minutes` below is sized against them.
        'timeout_seconds' => (int) env('REDFISH_TIMEOUT_SECONDS', 60),
        'verify_tls' => (bool) env('REDFISH_VERIFY_TLS', true),
        'ipmi_timeout_seconds' => (int) env('IPMI_TIMEOUT_SECONDS', 60),
    ],

    /*
     * How long a power request may hold its claim before the platform gives
     * up on the process that made it.
     *
     * A claim is committed before the management controller is called and
     * settled after it answers, so a worker that dies in between — a deploy,
     * an OOM kill — leaves a row nothing else would ever settle. Past this
     * lease the row is settled as indeterminate, never released: see
     * PowerClaimLease for why that is the only safe answer.
     *
     * Fifteen minutes, sized against the controller timeouts above rather
     * than chosen: a request makes at most two controller calls (a `cycle`
     * reads the chassis, then resets it), each bounded at 60 seconds by
     * default, so the longest honest window is about two minutes and the
     * lease is roughly seven times that. Nothing checks the relation. Raising
     * either timeout towards this figure lets a slow but live request look
     * abandoned, and a repeat of its key arriving meanwhile is told "we do not
     * know" about an operation the controller may have accepted. That is a
     * wrong answer, and never a second instruction to the chassis.
     */
    'power' => [
        'claim_lease_minutes' => (int) env('DEDICATED_POWER_CLAIM_LEASE_MINUTES', 15),
    ],

    /*
     * How long a PXE boot authorisation stays usable.
     *
     * Deliberately short. The authorisation exists so that a reinstall is a
     * recorded decision with an expiry rather than a standing configuration: a
     * machine left with PXE first in its boot order reinstalls itself the next
     * time it reboots for any reason, which is a customer's entire server
     * erased by a power cut.
     */
    'pxe' => [
        'authorisation_ttl_minutes' => (int) env('PXE_AUTHORISATION_TTL_MINUTES', 60),
        // Unattended installs are slow and vary by hardware; a RAID
        // initialisation alone can outlast a naive timeout.
        'install_timeout_minutes' => (int) env('PXE_INSTALL_TIMEOUT_MINUTES', 90),
    ],

    /*
     * An order for a hardware profile with nothing free goes to an operator
     * rather than failing.
     *
     * A dedicated server already exists or it does not, and no amount of
     * retrying conjures another one. A customer content to wait a day is worth
     * more than a refund, so the order waits in MANUAL_REVIEW.
     */
    'reservation' => [
        'hold_minutes' => (int) env('DEDICATED_RESERVATION_HOLD_MINUTES', 120),
        'send_to_review_when_unavailable' => true,
    ],

    /*
     * Where the controlled BMC simulator keeps what it remembers, so that
     * more than one process can see the same thing.
     *
     * Unset by default, and unset everywhere but the tests that need it: an
     * in-memory simulator is the right one inside a single process, and a
     * shared file would let one test's power states leak into the next test's.
     * It exists because a workflow in this platform crosses a request, a queue
     * and a worker, and a simulator that forgets at the process boundary can
     * take part in a contract test but not in a workflow.
     *
     * @see \Lynomia\Modules\Shared\Infrastructure\Simulation\ControlledSimulationStore
     */
    'fake' => [
        'state_path' => env('DEDICATED_FAKE_STATE_PATH'),
    ],
];
