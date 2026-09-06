<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Nothing free in that datacenter matches that hardware profile.
 *
 * Thrown rather than answered with null, and thrown rather than quietly
 * widened to "something similar". Both alternatives are worse than failing:
 *
 *  - a caller that carries on with no machine bills a customer for a server
 *    that was never allocated;
 *  - a caller that substitutes a different profile delivers hardware the
 *    customer did not buy, which on physical machines is not a smaller CPU but
 *    a different disk layout, a different NIC and a different price.
 *
 * The correct response is neither a refund nor a retry loop: the order goes to
 * MANUAL_REVIEW and an operator racks, frees or reassigns a machine. A
 * customer content to wait a day is worth more than a refund.
 */
final class NoMatchingHardwareException extends DomainException
{
    public static function forProfile(string $hardwareProfile, string $datacenterId, int $availableInDatacenter = 0): self
    {
        $exception = new self(sprintf(
            'No available server matches hardware profile "%s" in datacenter %s.',
            $hardwareProfile,
            $datacenterId,
        ));

        return $exception->withContext([
            'hardware_profile' => $hardwareProfile,
            'datacenter_id' => $datacenterId,
            // How much free stock of ANY profile the site has, because that is
            // the number that tells an operator whether this is "order more
            // hardware" or "one profile has run out".
            'available_in_datacenter' => $availableInDatacenter,
            // Read by the caller so the disposition is carried by the failure
            // itself rather than re-derived from its class at each call site.
            'disposition' => 'manual_review',
        ]);
    }

    public function errorCode(): string
    {
        return 'dedicated.no_matching_hardware';
    }

    /**
     * Stock exhaustion is a temporary condition of the platform, not a
     * malformed request.
     */
    public function httpStatus(): int
    {
        return 503;
    }
}
