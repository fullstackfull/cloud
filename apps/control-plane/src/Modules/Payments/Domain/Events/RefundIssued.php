<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Events;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A refund has been accepted by the provider.
 *
 * "Accepted", not "settled in the customer's account" — card refunds take days
 * to appear. Listeners that credit a wallet or reopen an invoice act on this;
 * anything that must wait for the money to actually land waits for the
 * provider's refund webhook instead.
 *
 * @immutable
 */
final readonly class RefundIssued
{
    public function __construct(
        public string $refundId,
        public string $transactionId,
        public string $customerId,
        public ?string $invoiceId,
        public string $provider,
        public Money $amount,
        /** What remains refundable on the transaction after this refund. */
        public Money $remainingRefundable,
        public string $reason,
        public ?string $issuedByUserId,
        public CarbonImmutable $issuedAt,
    ) {}

    /**
     * Whether the captured amount has now been returned in full, which is what
     * distinguishes a cancelled sale from a partial credit.
     */
    public function isFullRefund(): bool
    {
        return $this->remainingRefundable->isZero();
    }
}
