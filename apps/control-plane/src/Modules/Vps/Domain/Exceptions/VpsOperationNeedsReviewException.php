<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Domain\Exceptions;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * An earlier operation for this service is waiting for a person.
 *
 * Distinct from {@see VpsOperationInFlightException}, and the distinction is
 * the whole reason this class exists. A queued or running job will finish on
 * its own; a job at `needs_review` will not, because the engine put it there
 * on purpose — that status is where a TIMED-OUT operation comes to rest, and a
 * timeout means the platform stopped waiting, never that the hypervisor
 * stopped working.
 *
 * So the machine's real state is unknown. Accepting a reboot on top of it
 * sends a command to a guest that may be halfway through a rebuild; accepting
 * a second reinstall starts destroying a disk the first one may still be
 * writing. Neither is a retry the platform is allowed to make on its own, and
 * neither becomes safe because a customer pressed the button.
 *
 * The remedy is a person, so the message says so rather than inviting the
 * caller to try again in a moment.
 */
final class VpsOperationNeedsReviewException extends DomainException
{
    public static function forKind(ProvisioningJobKind $kind): self
    {
        return (new self(sprintf(
            'An earlier operation (%s) on this service did not complete and is waiting for an operator to '
            .'confirm what happened to it. Further operations are not accepted until it has been resolved.',
            $kind->value,
        )))->withContext(['unresolved_kind' => $kind->value]);
    }

    public function errorCode(): string
    {
        return 'vps.operation_needs_review';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
