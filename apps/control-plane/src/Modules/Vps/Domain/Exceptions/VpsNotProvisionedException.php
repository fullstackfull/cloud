<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The platform holds a row for this machine but the hypervisor has never
 * confirmed it — no provider id, or no node to reach it on.
 *
 * That state is not an error in itself: the row is deliberately written before
 * the create call returns, so that a lost response still leaves something to
 * reconcile against. It does mean there is nothing to send a power or console
 * request to, and inventing a target would be a guess about which machine on
 * which node the customer meant.
 */
final class VpsNotProvisionedException extends DomainException
{
    public static function make(): self
    {
        return new self(
            'This machine has not been confirmed by the hypervisor yet, so there is nothing to act on. '
            .'It will become available once provisioning finishes or an operator has reconciled it.'
        );
    }

    public function errorCode(): string
    {
        return 'vps.not_provisioned';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
