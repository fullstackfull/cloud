<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Exceptions;

use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A customer asked the platform to do something to a physical machine, and the
 * platform said no.
 *
 * Every constructor here guards a machine that is in somebody else's hands.
 * That is the whole family: the machine exists, it is the caller's, and this
 * is not the moment.
 *
 *  - **Maintenance is the case that matters.** An operator put the machine
 *    there — a firmware run, a disk swap, a wipe — and a customer power
 *    cycling a host mid-flash bricks it in a way no support ticket recovers.
 *    A machine in maintenance is out of the customer's hands until the person
 *    working on it puts it back.
 *  - `provisioning` is refused for the mirror-image reason: an installer is
 *    writing to the disks right now.
 *  - `failed` and `reserved` are refused because there is nothing running to
 *    act on, and answering "accepted" would be a lie a client renders as a
 *    spinner.
 *
 * A 409 rather than a 422: nothing about the request is malformed, and sending
 * it again in ten minutes may well work.
 */
final class DedicatedOperationRefusedException extends DomainException
{
    /**
     * The machine is not in service, so nothing may be asked of it.
     *
     * The status is carried in the context because it is the customer's own
     * machine and the answer to "why not?" is the only useful thing this
     * response has to say. It is not an id, an address or an internal name.
     */
    public static function becauseServerIsNotInService(string $serverId, DedicatedServerStatus $status): self
    {
        $exception = new self(match ($status) {
            DedicatedServerStatus::Maintenance => 'This server is under maintenance and cannot be operated until the work on it is finished.',
            DedicatedServerStatus::Provisioning => 'This server is being installed. Wait for the install to finish before operating it.',
            DedicatedServerStatus::Reserved => 'This server has not been installed yet.',
            DedicatedServerStatus::Failed => 'This server is marked as faulty and is being looked at by an engineer.',
            default => 'This server is not in service and cannot be operated.',
        });

        return $exception->withContext([
            'dedicated_server_id' => $serverId,
            'status' => $status->value,
            'required_status' => DedicatedServerStatus::Active->value,
        ]);
    }

    /**
     * Something the platform started is still running against this machine.
     *
     * Refused rather than queued behind it. Work accepted now and executed
     * later is work whose preconditions were true at a moment nobody recorded:
     * a reinstall queued behind a reinstall rebuilds a machine that is already
     * being rebuilt.
     */
    public static function becauseWorkIsAlreadyInFlight(string $serverId, ProvisioningJobKind $kind): self
    {
        $exception = new self(sprintf(
            'The platform is already carrying out a "%s" operation on this server. Wait for it to finish.',
            $kind->value,
        ));

        return $exception->withContext([
            'dedicated_server_id' => $serverId,
            'in_flight_kind' => $kind->value,
        ]);
    }

    public function errorCode(): string
    {
        return 'dedicated.operation_refused';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
