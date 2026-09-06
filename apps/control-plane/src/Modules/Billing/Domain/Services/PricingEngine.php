<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Lynomia\Modules\Billing\Domain\ValueObjects\LineTotal;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricedOrder;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Billing\Domain\ValueObjects\TaxRate;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Turns priced lines, a discount and a tax rate into a closed set of totals.
 *
 * Two design decisions carry most of the correctness here:
 *
 * 1. **Discounts are allocated across lines, never subtracted from the total.**
 *    Tax is computed per line, so a discount that only exists at the total
 *    level cannot be attributed to the right taxable base. Allocation is
 *    weighted by each line's gross and uses exact remainder distribution, so
 *    the parts always sum to the whole.
 *
 * 2. **Totals are the sum of the lines, not an independently rounded figure.**
 *    Computing the total separately is the classic source of invoices whose
 *    lines do not add up to the amount charged.
 */
final class PricingEngine
{
    /**
     * @param  list<PricingLine>  $lines
     * @param  Money|null  $fixedDiscount  a fixed-amount coupon
     * @param  string|null  $percentageDiscount  an exact decimal such as "0.10"
     */
    public function price(
        array $lines,
        TaxRate $taxRate,
        ?Money $fixedDiscount = null,
        ?string $percentageDiscount = null,
        ?string $couponCode = null,
    ): PricedOrder {
        if ($lines === []) {
            throw new \InvalidArgumentException('Cannot price an order with no lines.');
        }

        $currency = $lines[0]->unitPrice->currency();
        $this->assertOneCurrency($lines, $currency, $fixedDiscount);

        $grossByLine = array_map(static fn (PricingLine $line): Money => $line->gross(), $lines);
        $gross = $this->sum($grossByLine, $currency);

        $discount = $this->resolveDiscount($gross, $lines, $currency, $fixedDiscount, $percentageDiscount);
        $discountByLine = $this->allocateDiscount($discount, $lines, $grossByLine, $currency);

        $lineTotals = [];
        foreach ($lines as $index => $line) {
            $lineTotals[] = $this->priceLine(
                $grossByLine[$index],
                $discountByLine[$index],
                $taxRate,
            );
        }

        return new PricedOrder(
            lines: $lineTotals,
            // Subtotal is the taxable base after discount, which is what a
            // customer reading the invoice expects the tax to be a percentage of.
            subtotal: $this->sum(array_map(static fn (LineTotal $l): Money => $l->net, $lineTotals), $currency),
            discount: $this->sum(array_map(static fn (LineTotal $l): Money => $l->discount, $lineTotals), $currency),
            tax: $this->sum(array_map(static fn (LineTotal $l): Money => $l->tax, $lineTotals), $currency),
            total: $this->sum(array_map(static fn (LineTotal $l): Money => $l->total, $lineTotals), $currency),
            couponCode: $couponCode,
        );
    }

    private function priceLine(Money $gross, Money $discount, TaxRate $taxRate): LineTotal
    {
        if ($taxRate->isInclusive) {
            // The advertised price already contains tax, so the discount comes
            // off the gross and the remainder is then split.
            $discountedGross = $gross->minus($discount);
            ['net' => $net, 'tax' => $tax] = $taxRate->splitInclusive($discountedGross);

            return new LineTotal(
                gross: $gross,
                discount: $discount,
                net: $net,
                tax: $tax,
                total: $discountedGross,
                taxRate: $taxRate->rate,
                taxName: $taxRate->name,
            );
        }

        $net = $gross->minus($discount);
        $tax = $taxRate->taxOn($net);

        return new LineTotal(
            gross: $gross,
            discount: $discount,
            net: $net,
            tax: $tax,
            total: $net->plus($tax),
            taxRate: $taxRate->rate,
            taxName: $taxRate->name,
        );
    }

    /**
     * @param  list<PricingLine>  $lines
     */
    private function resolveDiscount(
        Money $gross,
        array $lines,
        string $currency,
        ?Money $fixedDiscount,
        ?string $percentageDiscount,
    ): Money {
        // Only discountable lines contribute to the base a percentage applies
        // to: a 10% coupon should not quietly discount a setup fee that the
        // catalogue marks as non-discountable.
        $discountableGross = $this->sum(
            array_map(
                static fn (PricingLine $line): Money => $line->discountable
                    ? $line->gross()
                    : Money::zero($line->unitPrice->currency()),
                $lines,
            ),
            $currency,
        );

        $discount = match (true) {
            $percentageDiscount !== null => $discountableGross->multipliedBy($percentageDiscount, RoundingMode::HalfUp),
            $fixedDiscount !== null => $fixedDiscount,
            default => Money::zero($currency),
        };

        // A coupon worth more than the order must not produce a negative total
        // that would read as the platform owing the customer money.
        return $discount->isGreaterThan($discountableGross) ? $discountableGross : $discount;
    }

    /**
     * Distributes a discount across lines in proportion to their gross.
     *
     * Uses integer-weighted allocation on minor units, which guarantees the
     * parts sum to exactly the discount — no fils created, none lost.
     *
     * @param  list<PricingLine>  $lines
     * @param  list<Money>  $grossByLine
     * @return list<Money>
     */
    private function allocateDiscount(Money $discount, array $lines, array $grossByLine, string $currency): array
    {
        $zero = Money::zero($currency);

        if ($discount->isZero()) {
            return array_fill(0, count($lines), $zero);
        }

        $weights = [];
        foreach ($lines as $index => $line) {
            $weights[] = $line->discountable ? $grossByLine[$index]->minorUnits() : 0;
        }

        if (array_sum($weights) === 0) {
            return array_fill(0, count($lines), $zero);
        }

        return $discount->allocate($weights);
    }

    /**
     * @param  list<Money>  $amounts
     */
    private function sum(array $amounts, string $currency): Money
    {
        return array_reduce(
            $amounts,
            static fn (Money $carry, Money $amount): Money => $carry->plus($amount),
            Money::zero($currency),
        );
    }

    /**
     * @param  list<PricingLine>  $lines
     */
    private function assertOneCurrency(array $lines, string $currency, ?Money $fixedDiscount): void
    {
        foreach ($lines as $line) {
            if ($line->unitPrice->currency() !== $currency || $line->setupFee->currency() !== $currency) {
                throw CurrencyMismatchException::between($currency, $line->unitPrice->currency());
            }
        }

        if ($fixedDiscount !== null && $fixedDiscount->currency() !== $currency) {
            throw CurrencyMismatchException::between($currency, $fixedDiscount->currency());
        }
    }

    /**
     * Proration for a mid-cycle change.
     *
     * Charges for the fraction of the period actually used, measured in whole
     * seconds so that an hourly plan and a yearly plan use one rule. The
     * unused-time credit and the new charge are computed from the same divisor,
     * so an upgrade and an immediate downgrade cancel out to zero rather than
     * leaking a fils each time.
     */
    public function prorate(
        Money $periodAmount,
        \DateTimeInterface $periodStart,
        \DateTimeInterface $periodEnd,
        \DateTimeInterface $changeAt,
    ): Money {
        $totalSeconds = $periodEnd->getTimestamp() - $periodStart->getTimestamp();

        if ($totalSeconds <= 0) {
            return Money::zero($periodAmount->currency());
        }

        $remainingSeconds = max(0, min($totalSeconds, $periodEnd->getTimestamp() - $changeAt->getTimestamp()));

        if ($remainingSeconds === 0) {
            return Money::zero($periodAmount->currency());
        }

        $fraction = (string) BigDecimal::of($remainingSeconds)
            ->dividedBy($totalSeconds, 12, RoundingMode::HalfUp);

        return $periodAmount->multipliedBy($fraction, RoundingMode::HalfUp);
    }
}
