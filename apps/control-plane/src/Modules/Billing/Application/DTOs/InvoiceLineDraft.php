<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\DTOs;

use DateTimeImmutable;
use InvalidArgumentException;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\ValueObjects\LineTotal;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricedOrder;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * One line as it will be written onto an invoice.
 *
 * The draft pairs the description a human reads with the LineTotal the pricing
 * engine computed, and nothing recomputes either: an invoice line is a record
 * of a figure that was already agreed, so re-deriving it at write time would
 * only create an opportunity for the two to disagree.
 *
 * @immutable
 */
final readonly class InvoiceLineDraft
{
    public function __construct(
        public InvoiceItemKind $kind,
        public string $description,
        public int $quantity,
        /** The catalogue price of one unit, for the line to be readable. */
        public Money $unitAmount,
        public LineTotal $totals,
        public ?DateTimeImmutable $periodStart = null,
        public ?DateTimeImmutable $periodEnd = null,
        public ?string $subscriptionId = null,
    ) {
        if ($quantity < 1) {
            throw new InvalidArgumentException('An invoice line must bill at least one unit.');
        }
    }

    /**
     * Pairs a line that was priced with the totals the engine produced for it.
     *
     * A setup fee is part of the line's gross and therefore of its total, but
     * the invoice schema has no separate column for it, so the unit amount
     * shown stays the recurring unit price. Where a setup fee has to appear on
     * its own line, price it as its own PricingLine.
     */
    public static function fromPricing(
        PricingLine $line,
        LineTotal $totals,
        InvoiceItemKind $kind = InvoiceItemKind::Plan,
        ?DateTimeImmutable $periodStart = null,
        ?DateTimeImmutable $periodEnd = null,
        ?string $subscriptionId = null,
    ): self {
        return new self(
            kind: $kind,
            description: $line->description,
            quantity: $line->quantity,
            unitAmount: $line->unitPrice,
            totals: $totals,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            subscriptionId: $subscriptionId,
        );
    }

    /**
     * Zips a priced order back together with the lines that were submitted.
     *
     * The pricing engine returns one LineTotal per input line, in order, so the
     * pairing is positional. The count is asserted rather than assumed: a
     * mismatch would silently attach one line's money to another line's
     * description, which is the kind of invoice nobody can reconcile later.
     *
     * @param  list<PricingLine>  $pricingLines
     * @return list<self>
     */
    public static function zip(
        PricedOrder $priced,
        array $pricingLines,
        InvoiceItemKind $kind = InvoiceItemKind::Plan,
        ?DateTimeImmutable $periodStart = null,
        ?DateTimeImmutable $periodEnd = null,
        ?string $subscriptionId = null,
    ): array {
        if (count($pricingLines) !== count($priced->lines)) {
            throw new InvalidArgumentException(sprintf(
                'A priced order with %d lines cannot be described by %d pricing lines.',
                count($priced->lines),
                count($pricingLines),
            ));
        }

        $drafts = [];
        foreach ($pricingLines as $index => $line) {
            $drafts[] = self::fromPricing(
                $line,
                $priced->lines[$index],
                $kind,
                $periodStart,
                $periodEnd,
                $subscriptionId,
            );
        }

        return $drafts;
    }

    public function currency(): string
    {
        return $this->totals->total->currency();
    }
}
