<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Domains\Domain\DTOs\AvailabilityAnswer;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Domain\Services\DomainPricing;
use Lynomia\Modules\Domains\Domain\ValueObjects\RegistrableDomain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainQuote;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * Turns "what would this cost" into a number the platform will honour.
 *
 * ---------------------------------------------------------------------------
 * The attack this exists to close
 * ---------------------------------------------------------------------------
 *
 * A premium domain's price is set by the registry per name, and it can be a
 * hundred times the list price for its TLD. If a checkout accepted an amount
 * from the client — or a name plus a TLD and looked the price up at order time
 * from a different code path than the search used — somebody would buy
 * `insurance.com` for the price of an unremarkable `.com`.
 *
 * So the price is computed here, written to a row, and the customer is handed
 * an id. Checkout re-reads the row. Nothing about the amount travels through
 * the browser, and the only thing a tampered request can change is which quote
 * is being redeemed — which is checked against the acting account.
 *
 * ---------------------------------------------------------------------------
 * Cost and margin, recorded rather than derived
 * ---------------------------------------------------------------------------
 *
 * Both sides are written down at the moment they were agreed. A margin
 * recomputed later from today's price list is a margin nobody can reconcile
 * against what was actually charged, and this platform will change its prices.
 */
final readonly class QuoteDomain
{
    public function __construct(
        private DomainPricing $pricing,
    ) {}

    /**
     * @param  ?AvailabilityAnswer  $answer  What the registrar said, when the
     *                                       caller has already asked. A premium
     *                                       answer carries a price the TLD list
     *                                       does not have, and passing it here
     *                                       is how that price reaches the quote
     *                                       without a second provider call.
     *
     * @throws DomainRefusedException
     */
    public function execute(
        Customer $customer,
        RegistrableDomain $domain,
        DomainOperationKind $kind,
        int $termYears = 1,
        ?AvailabilityAnswer $answer = null,
    ): DomainQuote {
        $tld = DomainTld::query()->where('tld', $domain->tld)->first();

        if (! $tld instanceof DomainTld) {
            throw DomainRefusedException::becauseTheTldIsNotSold($domain->tld);
        }

        $priced = $this->pricing->forTerm($tld, $kind, $termYears, $answer);

        return DomainQuote::query()->create([
            'customer_id' => $customer->getKey(),
            'name' => $domain->name,
            'tld' => $domain->tld,
            'operation' => $kind,
            'term_years' => $termYears,
            'premium' => $priced->premium,
            'currency' => $priced->price->currency(),
            'price_minor' => $priced->price->minorUnits(),
            'cost_minor' => $priced->cost?->minorUnits(),
            'provider' => $tld->provider,
            'provider_reference' => $priced->providerReference,
            'expires_at' => CarbonImmutable::now()->addMinutes(
                max(1, (int) config('domains.quote_ttl_minutes', 15)),
            ),
        ]);
    }
}
