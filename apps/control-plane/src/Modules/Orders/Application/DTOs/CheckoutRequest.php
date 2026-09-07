<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\DTOs;

use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;

/**
 * What a customer asked to buy, before anything has been priced or reserved.
 *
 * @immutable
 */
final readonly class CheckoutRequest
{
    /**
     * @param  list<CheckoutLine>  $lines
     * @param  string|null  $idempotencyKey  Supplied by the client. A repeated submission with the
     *                                       same key returns the original order rather than placing
     *                                       a second one, which is what makes a double-clicked
     *                                       purchase and a retried mobile request safe.
     */
    public function __construct(
        public array $lines,
        public BillingPeriod $billingPeriod,
        public ?string $couponCode = null,
        public ?string $idempotencyKey = null,
        public ?string $notes = null,
    ) {}

    /**
     * A stable digest of what was actually asked for.
     *
     * The idempotency key answers "have I seen this key before?"; this answers
     * "was it the same request?". Without the second question a client that
     * reuses a key for a genuinely different basket - a retry built from a
     * stale form, a queue replaying a message after the cart changed, a library
     * deriving the key from the session rather than the payload - is silently
     * handed the first order, and the customer gets a confirmation for
     * something they did not just buy.
     *
     * Lines are sorted before hashing, so the same basket submitted in a
     * different order is the same basket. Notes are excluded: they do not
     * change what is bought or what it costs, and a customer who retries after
     * fixing a typo in a delivery note should get their original order back
     * rather than a conflict.
     */
    public function fingerprint(): string
    {
        $lines = array_map(
            static fn (CheckoutLine $line): string => $line->planId.':'.$line->quantity,
            $this->lines,
        );

        sort($lines);

        return hash('sha256', implode('|', [
            $this->billingPeriod->value,
            strtolower(trim((string) $this->couponCode)),
            implode(',', $lines),
        ]));
    }
}
