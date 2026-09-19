<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Actions;

use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;

/**
 * Whether this platform will invoice for another term before the name lapses.
 *
 * ---------------------------------------------------------------------------
 * Why no registrar call
 * ---------------------------------------------------------------------------
 *
 * Because auto-renew here is not the registrar's flag. `SweepDomainLifecycle`
 * reads this column, raises an invoice inside the lead time, and asks the
 * registry only once the money has arrived — the same path a customer's
 * "Renew now" takes. Setting a flag at the registrar as well would create a
 * second renewal authority: two systems that could each decide to renew, and a
 * customer billed twice the day they disagree.
 *
 * So this writes one column, and the column is honest about what it controls.
 *
 * ---------------------------------------------------------------------------
 * What turning it off does not mean
 * ---------------------------------------------------------------------------
 *
 * It does not cancel the name, release it, or shorten the term already paid
 * for. The domain runs to its expiry either way; what changes is whether the
 * platform prepares the next term. The screen says exactly that, because
 * "auto-renew off" reads to a lot of people like "cancelled now".
 */
final readonly class SetDomainAutoRenew
{
    /**
     * @throws DomainRefusedException
     */
    public function execute(Domain $domain, bool $autoRenew): Domain
    {
        /*
         * The states the sweep acts on are the states where the setting means
         * something: active, expired inside its grace window, and in grace.
         * A name in redemption or already gone cannot be renewed by paying a
         * renewal fee, and offering the toggle there would be offering a
         * setting with no effect.
         */
        if (! $domain->state->isManageable()) {
            throw DomainRefusedException::becauseTheStateForbidsIt($domain->name, $domain->state);
        }

        $domain->auto_renew = $autoRenew;
        $domain->save();

        return $domain->refresh();
    }
}
