<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Actions;

use Lynomia\Modules\Domains\Domain\Enums\RegistrarCapability;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRegistrarException;
use Lynomia\Modules\Domains\Domain\Exceptions\RegistrarNotAvailableException;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;

/**
 * The lock that stops a name being taken.
 *
 * A registrar lock is the single most effective defence a domain has against
 * being stolen, and it is off by exactly the customers who most need it on.
 * This platform locks a name when it registers it and offers the switch here,
 * rather than leaving it to a checkbox nobody finds.
 *
 * Unlocking is a real risk and is treated as one: it is the step immediately
 * before an authorisation code is handed out, and together they are how a name
 * leaves. Both are audited, and both take the permission that manages
 * services rather than the one that reads them.
 */
final readonly class SetTransferLock
{
    public function __construct(
        private DomainRegistrarFactory $registrars,
    ) {}

    /**
     * @throws DomainRefusedException
     * @throws DomainRegistrarException
     * @throws RegistrarNotAvailableException
     */
    public function execute(Domain $domain, bool $locked): Domain
    {
        if (! $domain->state->isManageable()) {
            throw DomainRefusedException::becauseTheStateForbidsIt($domain->name, $domain->state);
        }

        $provider = $this->registrars->make($domain->provider);

        if (! $provider->supports(RegistrarCapability::TransferLock)) {
            throw DomainRefusedException::becauseTheProviderCannot(RegistrarCapability::TransferLock->value);
        }

        $provider->setTransferLock($domain->name, $locked);

        $domain->forceFill(['transfer_locked' => $locked])->save();

        return $domain;
    }
}
