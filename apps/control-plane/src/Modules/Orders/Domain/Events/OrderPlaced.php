<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Domain\Events;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A customer has committed to a purchase.
 *
 * Raised once, by the checkout that created the order, and never by the loser
 * of an idempotency race — a double-clicked buy button is one purchase and must
 * announce itself once.
 *
 * The order is placed and priced by the time this fires; what has *not* happened
 * is anything that asks the customer for money. That is the point of the event:
 * the platform's fulfilment chain runs
 *
 *     OrderPlaced     → issue the invoice
 *     PaymentCaptured → settle it
 *     InvoicePaid     → fulfil the order
 *
 * and the first link is what turns a placed order into something payable.
 *
 * @immutable
 */
final readonly class OrderPlaced
{
    public function __construct(
        public string $orderId,
        public string $customerId,
        public Money $total,
        public CarbonImmutable $placedAt,
    ) {}
}
