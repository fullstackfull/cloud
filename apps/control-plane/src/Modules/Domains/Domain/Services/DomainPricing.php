<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Services;

use Lynomia\Modules\Domains\Domain\DTOs\AvailabilityAnswer;
use Lynomia\Modules\Domains\Domain\DTOs\PricedTerm;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * The one place a domain price is worked out.
 *
 * ---------------------------------------------------------------------------
 * Why this is a service and not two private methods
 * ---------------------------------------------------------------------------
 *
 * Two callers need the same number: the search screen, which shows a price
 * before anybody has committed to anything, and {@see QuoteDomain}, which
 * writes the price the platform will honour. If those were two
 * implementations, the interesting failure is not that they disagree loudly —
 * it is that they agree on ordinary names and diverge on premium ones, which
 * is precisely where the money is and precisely the case nobody clicks through
 * by hand.
 *
 * So the search shows what the quote will say, because it is the same code.
 * The search's number is still not authoritative: what the platform honours is
 * the quote row, and that is a database fact rather than a screen.
 */
final readonly class DomainPricing
{
    /**
     * @param  ?AvailabilityAnswer  $answer  What the registrar said, where the
     *                                       caller has already asked. Only a
     *                                       premium answer changes the price.
     *
     * @throws DomainRefusedException
     */
    public function forTerm(
        DomainTld $tld,
        DomainOperationKind $kind,
        int $termYears,
        ?AvailabilityAnswer $answer = null,
    ): PricedTerm {
        if (! $tld->permits($kind)) {
            throw DomainRefusedException::becauseTheOperationIsNotOffered($tld->tld, $kind);
        }

        if (! $tld->permitsTerm($termYears)) {
            throw DomainRefusedException::becauseTheTermIsNotPermitted($tld->tld, $termYears);
        }

        return $answer !== null && $answer->availability->needsItsOwnPrice()
            ? $this->premium($tld, $answer, $termYears)
            : $this->fromTheList($tld, $kind, $termYears);
    }

    /**
     * The ordinary case: the TLD's list price, times the term.
     */
    private function fromTheList(DomainTld $tld, DomainOperationKind $kind, int $termYears): PricedTerm
    {
        $unit = $tld->priceFor($kind);

        if ($unit === null) {
            /*
             * Reached for redemption on a TLD whose penalty this platform has
             * never been told. Quoting a guess would be quoting a registry fee
             * somebody then has to pay.
             */
            throw DomainRefusedException::becauseTheOperationIsNotOffered($tld->tld, $kind);
        }

        return new PricedTerm(
            price: $unit->multipliedBy($termYears),
            cost: $tld->costFor($kind)?->multipliedBy($termYears),
            premium: false,
        );
    }

    /**
     * A name the registry prices for itself.
     *
     * The provider's number is a **cost**, and the platform's margin goes on
     * top of it — the same margin it earns on an ordinary name of that TLD,
     * expressed as the ratio between the list price and the list cost. A
     * platform that sold premium names at cost would be donating the
     * difference on exactly the names where the money is.
     *
     * Where the TLD's own cost is unknown the registry's price is passed
     * through with no margin, and that is stated rather than guessed:
     * inventing a markup would be inventing revenue.
     */
    private function premium(DomainTld $tld, AvailabilityAnswer $answer, int $termYears): PricedTerm
    {
        $cost = $answer->premiumCost;

        /*
         * A provider that called a name premium and then did not say what it
         * costs has not given anybody a price to charge. Refused rather than
         * fallen back to the list price, which would sell a premium name at an
         * ordinary one.
         */
        if ($cost === null) {
            throw DomainRefusedException::becauseTheProviderCannot('premium_pricing');
        }

        $listPrice = $tld->registration_price_minor;
        $listCost = $tld->registration_cost_minor;

        /*
         * Integer arithmetic throughout, and rounded up. Money is minor units
         * everywhere in this platform, and a float here would be a float in a
         * price a registry has to be paid exactly.
         */
        $priceMinor = $listCost !== null && $listCost > 0
            ? (int) ceil($cost->minorUnits() * $listPrice / $listCost)
            : $cost->minorUnits();

        return new PricedTerm(
            price: Money::ofMinor($priceMinor, $cost->currency())->multipliedBy($termYears),
            cost: $cost->multipliedBy($termYears),
            premium: true,
            providerReference: $answer->providerReference,
        );
    }
}
