<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Events;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A payment attempt was declined or errored at the provider.
 *
 * The failure code is the provider's, kept verbatim: dunning policy depends on
 * telling "insufficient_funds" (retry in a day) apart from "card_declined"
 * (ask for another card) apart from "expired_card" (ask for a new expiry).
 *
 * @immutable
 */
final readonly class PaymentFailed
{
    public function __construct(
        public string $transactionId,
        public string $customerId,
        public ?string $invoiceId,
        public string $provider,
        public string $providerReference,
        public Money $amount,
        public ?string $failureCode,
        public ?string $failureMessage,
        public CarbonImmutable $failedAt,
    ) {}
}
