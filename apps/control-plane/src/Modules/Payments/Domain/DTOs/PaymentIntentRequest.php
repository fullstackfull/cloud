<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\DTOs;

use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Everything a provider needs to start collecting one payment.
 *
 * The idempotency key is required rather than optional. A payment request that
 * can be retried without one is a request that can charge a customer twice
 * when a socket times out after the provider has already taken the money.
 *
 * @immutable
 */
final readonly class PaymentIntentRequest
{
    /**
     * @param  string  $idempotencyKey  Stable across retries of the same logical payment.
     * @param  array<string, string>  $metadata  Echoed back on every webhook about this payment; this is how a
     *                                           capture is attributed to a customer and an invoice.
     */
    public function __construct(
        public Money $amount,
        public string $idempotencyKey,
        public ?string $customerReference = null,
        public ?string $paymentMethodReference = null,
        public ?string $description = null,
        public array $metadata = [],
        public ?string $returnUrl = null,
        /** Charge immediately rather than only authorising. */
        public bool $confirm = false,
        /** Charge without the customer present, e.g. a subscription renewal. */
        public bool $offSession = false,
    ) {}

    /**
     * The metadata as it will be sent, with the platform's own attribution
     * keys guaranteed present when they were supplied.
     *
     * @return array<string, string>
     */
    public function metadataWith(string $key, ?string $value): array
    {
        if ($value === null) {
            return $this->metadata;
        }

        return [...$this->metadata, $key => $value];
    }
}
