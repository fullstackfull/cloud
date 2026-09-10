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
        'timeout_seconds' => (int) env('REDFISH_TIMEOUT_SECONDS', 60),
        'verify_tls' => (bool) env('REDFISH_VERIFY_TLS', true),
        'ipmi_timeout_seconds' => (int) env('IPMI_TIMEOUT_SECONDS', 60),
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
];
