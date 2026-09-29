<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A service with no resource row here whose build may nevertheless have left
 * something at a provider.
 *
 * Refused whoever asks and whatever `force` says: the override skips a
 * retention window, and the window is not the question. The question is
 * whether a machine, an account or a reserved chassis exists that nothing on
 * this platform points at, and ending the service would make it unfindable —
 * running, unbilled and holding an address. The way out is to settle the
 * build first: adopt what the provider has, or record that it has nothing.
 */
final class ABuildMayExistException extends DomainException
{
    public static function forService(string $serviceId, string $evidence): self
    {
        $exception = new self(sprintf(
            'This service has nothing recorded on this platform, but its build may have left something at the provider: %s. Settle the build — adopt what exists, or record that nothing does — before ending the service.',
            $evidence,
        ));

        return $exception->withContext(['service_id' => $serviceId, 'build_evidence' => $evidence]);
    }

    public function errorCode(): string
    {
        return 'provisioning.build_may_exist';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
