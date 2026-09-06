<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Billing\Domain\ValueObjects\TaxRate;
use Lynomia\Modules\Catalog\Infrastructure\Models\TaxRule;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * Answers "what tax applied to this jurisdiction, at this instant?".
 *
 * Two rules govern every answer this class gives:
 *
 *  1. **The most specific rule wins.** A state rule beats the country-wide one
 *     it sits inside; a country with no state rule falls back to the national
 *     rate. Both are fetched in one query and ordered, rather than queried in
 *     two rounds, so the fallback cannot race a rule being edited between them.
 *
 *  2. **An unconfigured jurisdiction is zero-rated, never an error.** A country
 *     nobody has entered a rule for is far more likely to be a country the
 *     platform owes no tax in than a misconfiguration, and either way refusing
 *     to resolve would take checkout down for every customer there. A missing
 *     rate is an operator's reporting problem; a blocked sale is the company's.
 */
final class TaxResolver
{
    /**
     * Resolution takes the instant explicitly rather than reading the clock.
     *
     * An invoice is a statement about a moment. Reissuing it, rendering a PDF
     * of it, or auditing it two years later must all resolve the rate that was
     * in force when it was issued — not today's. If this method defaulted to
     * "now", every one of those paths would silently re-rate historical
     * invoices the first time a jurisdiction changed its VAT, and the reissued
     * document would no longer match the money that was actually taken.
     */
    public function resolve(?string $country, ?string $state, DateTimeInterface $at): TaxRate
    {
        $country = $this->normalizeCountry($country);

        if ($country === null) {
            return TaxRate::zero();
        }

        $state = $this->normalizeState($state);

        $rule = TaxRule::query()
            ->effectiveAt($at)
            ->where('country', $country)
            ->where(function (Builder $query) use ($state): void {
                $query->whereNull('state');

                if ($state !== null) {
                    // Stored states are free text ("Kuwait City", "kuwait
                    // city"), so the comparison is case-folded on both sides.
                    $query->orWhereRaw('lower(state) = ?', [$state]);
                }
            })
            // FALSE sorts before TRUE, so a state-level rule is preferred over
            // the country-wide one it is nested in.
            ->orderByRaw('(state IS NULL)')
            // Where a jurisdiction has overlapping rules — a correction
            // entered after the fact, say — the one that came into force most
            // recently is the operative one. The ULID tie-breaks two rules
            // that start at the same second in favour of the later entry.
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $rule?->taxRate() ?? TaxRate::zero();
    }

    /**
     * The rate for a customer's own billing address at a given instant.
     *
     * Exemption is checked before any lookup: an exempt customer is zero-rated
     * everywhere, and a rule existing for their country must not override the
     * exemption certificate their account carries.
     */
    public function forCustomer(Customer $customer, DateTimeInterface $at): TaxRate
    {
        if ($customer->tax_exempt) {
            return TaxRate::zero();
        }

        return $this->resolve($customer->country, $customer->state, $at);
    }

    private function normalizeCountry(?string $country): ?string
    {
        $country = strtoupper(trim((string) $country));

        // The column is char(2); anything else could only ever match nothing,
        // and is treated as an address we have no jurisdiction for.
        return strlen($country) === 2 ? $country : null;
    }

    private function normalizeState(?string $state): ?string
    {
        $state = mb_strtolower(trim((string) $state));

        return $state === '' ? null : $state;
    }
}
