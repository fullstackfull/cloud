<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Exceptions;

use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A network install was asked for and refused.
 *
 * Every constructor here guards the same thing: a PXE boot erases whatever is
 * on the machine. The refusals are not validation niceties — each one is a
 * customer's server that would otherwise have been wiped by a job that had no
 * business touching it.
 */
final class PxeAuthorisationRefusedException extends DomainException
{
    /**
     * The machine is not being provisioned, so nothing may reinstall it.
     *
     * `active` is the case that matters: a running customer server reached by
     * a stale or mis-addressed provisioning job. `available` matters almost as
     * much — a machine nobody has ordered has no install to run, and letting
     * one through means an unattended installer racing whoever eventually buys
     * the machine.
     */
    public static function becauseServerIsNotProvisioning(string $serverId, DedicatedServerStatus $status): self
    {
        $exception = new self(sprintf(
            'A PXE boot cannot be authorised for server %s: it is "%s", and only a server being provisioned may be reinstalled.',
            $serverId,
            $status->value,
        ));

        return $exception->withContext([
            'dedicated_server_id' => $serverId,
            'status' => $status->value,
            'required_status' => DedicatedServerStatus::Provisioning->value,
        ]);
    }

    /**
     * The window closed. The authorisation is not renewed in place: a fresh
     * decision, with a fresh reason and a fresh authoriser, is recorded
     * instead, so that the audit trail says who decided to reinstall a machine
     * and when — not who decided it once, months ago.
     */
    public static function becauseAuthorisationExpired(string $authorisationId, string $expiredAt): self
    {
        $exception = new self(sprintf(
            'PXE authorisation %s expired at %s and can no longer be used.',
            $authorisationId,
            $expiredAt,
        ));

        return $exception->withContext([
            'pxe_boot_authorisation_id' => $authorisationId,
            'expires_at' => $expiredAt,
        ]);
    }

    /**
     * The authorisation has already been spent or withdrawn.
     */
    public static function becauseAuthorisationIsFinished(string $authorisationId, string $status): self
    {
        $exception = new self(sprintf(
            'PXE authorisation %s is "%s" and can no longer be used.',
            $authorisationId,
            $status,
        ));

        return $exception->withContext([
            'pxe_boot_authorisation_id' => $authorisationId,
            'status' => $status,
        ]);
    }

    /**
     * No NIC address is recorded, so the boot server cannot be told which
     * machine is allowed to install.
     *
     * Refused rather than defaulted to a wildcard. DHCP and PXE on the
     * provisioning VLAN answer whoever asks; an authorisation with no MAC is
     * an authorisation for every machine on that VLAN.
     */
    public static function becauseNoMacAddressIsKnown(string $serverId): self
    {
        $exception = new self(sprintf(
            'Server %s has no recorded NIC MAC address, so no machine can be named as the one allowed to boot.',
            $serverId,
        ));

        return $exception->withContext(['dedicated_server_id' => $serverId]);
    }

    /**
     * The decision was not attributed. A reinstall is a destructive act and
     * the row that records it is the only place the reason survives.
     */
    public static function becauseNoReasonWasGiven(string $serverId): self
    {
        $exception = new self(sprintf(
            'A PXE boot for server %s must be authorised with a recorded reason.',
            $serverId,
        ));

        return $exception->withContext(['dedicated_server_id' => $serverId]);
    }

    public function errorCode(): string
    {
        return 'dedicated.pxe_authorisation_refused';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
