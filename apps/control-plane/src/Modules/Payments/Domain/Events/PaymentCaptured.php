<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Events;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Money has been captured and recorded in the ledger.
 *
 * Carries identifiers and a Money rather than the Transaction model so that a
 * queued listener deserialises to the same facts the event was raised with,
 * and so nothing downstream can mutate the transaction through the event.
 *
 * The event says only that a payment settled. Marking an invoice paid, closing
 * an order or provisioning a service are separate decisions taken by listeners
 * the coordinator wires up: the payments module must not be the place where
 * billing policy lives.
 *
 * @immutable
 */
final readonly class PaymentCaptured
{
    public function __construct(
        public string $transactionId,
        public string $customerId,
        public ?string $invoiceId,
        public string $provider,
        public string $providerReference,
        public Money $amount,
        public CarbonImmutable $capturedAt,
    ) {}
}
