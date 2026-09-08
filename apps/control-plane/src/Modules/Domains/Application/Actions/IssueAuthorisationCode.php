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
 * The code that lets a customer take their name somewhere else.
 *
 * ---------------------------------------------------------------------------
 * Why this exists and is not made difficult
 * ---------------------------------------------------------------------------
 *
 * Because a domain belongs to the customer, not to the hosting company, and a
 * platform that makes leaving hard is a platform that has stopped competing on
 * being worth staying with. Registrars that bury this are why the practice is
 * regulated.
 *
 * So: it is one request, it takes the same permission as any other management
 * action, and it is refused only where the registry itself would refuse — a
 * name inside its first sixty days, or one already in a transfer.
 *
 * ---------------------------------------------------------------------------
 * What is done with the code
 * ---------------------------------------------------------------------------
 *
 * It is fetched from the registrar and handed to the caller. It is **not**
 * stored. An auth code is a bearer credential for the whole domain: a stored
 * copy is a copy that leaks, and the registrar can always issue another.
 *
 * The act is audited — who asked, for which name, when — without the code
 * itself, for the same reason.
 */
final readonly class IssueAuthorisationCode
{
    public function __construct(
        private DomainRegistrarFactory $registrars,
    ) {}

    /**
     * @throws DomainRefusedException
     * @throws DomainRegistrarException
     * @throws RegistrarNotAvailableException
     */
    public function execute(Domain $domain): string
    {
        if (! $domain->state->isManageable()) {
            throw DomainRefusedException::becauseTheStateForbidsIt($domain->name, $domain->state);
        }

        $provider = $this->registrars->make($domain->provider);

        if (! $provider->supports(RegistrarCapability::AuthCode)) {
            throw DomainRefusedException::becauseTheProviderCannot(RegistrarCapability::AuthCode->value);
        }

        return $provider->authorisationCode($domain->name);
    }
}
