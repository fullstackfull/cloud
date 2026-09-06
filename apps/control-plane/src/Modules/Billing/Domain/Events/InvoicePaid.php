<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Events;

use Carbon\CarbonImmutable;

/**
 * An invoice has been settled in full.
 *
 * Raised only on the transition to paid, never on a partial settlement, so a
 * listener that fulfils an order cannot fire while money is still outstanding.
 *
 * @immutable
 */
final readonly class InvoicePaid
{
    public function __construct(
        public string $invoiceId,
        public string $customerId,
        public ?string $orderId,
        public ?string $subscriptionId,
        public CarbonImmutable $paidAt,
    ) {}
}
