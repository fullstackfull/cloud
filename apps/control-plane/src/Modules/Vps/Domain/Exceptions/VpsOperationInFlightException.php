<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Domain\Exceptions;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Another provisioning job for this service has not finished.
 *
 * Two lifecycle operations against one machine at once is how a reinstall gets
 * a stop halfway through its rebuild. The engine serialises nothing for us —
 * it is deliberately a job runner, not a scheduler — so the refusal belongs
 * here, in front of the queue rather than inside it.
 *
 * This is not the idempotency check. Replaying the *same* idempotency key
 * returns the job that already exists and never reaches this refusal; only a
 * genuinely new intent arriving on top of live work is refused.
 */
final class VpsOperationInFlightException extends DomainException
{
    public static function forKind(ProvisioningJobKind $kind): self
    {
        return (new self(sprintf(
            'Another operation (%s) is still running for this service. Wait for it to finish before requesting another.',
            $kind->value,
        )))->withContext(['in_flight_kind' => $kind->value]);
    }

    public function errorCode(): string
    {
        return 'vps.operation_in_flight';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
