<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Actions;

use Lynomia\Modules\Domains\Domain\Enums\RegistrarCapability;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRegistrarException;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;

/**
 * Where a name points.
 *
 * ---------------------------------------------------------------------------
 * The delegation is the product
 * ---------------------------------------------------------------------------
 *
 * A registered domain with nobody's nameservers on it serves nothing. This is
 * the step that turns a purchase into something that resolves, and it is the
 * step a customer will come back to every time they move a site.
 *
 * ---------------------------------------------------------------------------
 * Written at the registry first, recorded second
 * ---------------------------------------------------------------------------
 *
 * The order is deliberate. A row updated before the registry agreed would show
 * a customer a delegation the world does not have — and they would spend the
 * afternoon debugging their own DNS. If the registry refuses, nothing here
 * changes and the refusal is what the customer sees.
 *
 * A timeout is not a refusal. The delegation may well have been accepted, so
 * the stored copy is marked stale rather than assumed wrong: reconciliation
 * reads the registry and settles it. Guessing either way puts a wrong answer
 * on a screen with no way to tell.
 */
final readonly class SetDomainNameservers
{
    public function __construct(
        private DomainRegistrarFactory $registrars,
    ) {}

    /**
     * @param  list<string>  $nameservers
     *
     * @throws DomainRefusedException
     * @throws DomainRegistrarException
     */
    public function execute(Domain $domain, array $nameservers): Domain
    {
        if (! $domain->state->isManageable()) {
            throw DomainRefusedException::becauseTheStateForbidsIt($domain->name, $domain->state);
        }

        /*
         * Two is the floor every registry enforces, and enforcing it here
         * means the customer gets a sentence rather than a registry error
         * code. Thirteen is the ceiling the same registries use.
         */
        $nameservers = array_values(array_unique(array_map(
            static fn (string $host): string => strtolower(trim($host, " \t\n\r\0\x0B.")),
            $nameservers,
        )));

        if (count($nameservers) < 2 || count($nameservers) > 13) {
            throw DomainRefusedException::becauseTheNameserversAreNotUsable($domain->name, count($nameservers));
        }

        $provider = $this->registrars->make($domain->provider);

        if (! $provider->supports(RegistrarCapability::Nameservers)) {
            throw DomainRefusedException::becauseTheProviderCannot(RegistrarCapability::Nameservers->value);
        }

        try {
            $provider->setNameservers($domain->name, $nameservers);
        } catch (DomainRegistrarException $e) {
            if ($e->isIndeterminate()) {
                /*
                 * The registry may have taken the change. The stored copy is
                 * left alone and marked for reconciliation rather than
                 * overwritten with a guess — a delegation is the one field
                 * where a wrong answer on the screen sends the customer
                 * debugging the wrong thing.
                 */
                $domain->forceFill(['reconciled_at' => null])->save();
            }

            throw $e;
        }

        $domain->forceFill([
            'nameservers' => $nameservers,
            'reconciled_at' => null,
        ])->save();

        return $domain;
    }
}
