<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\DTOs;

use Lynomia\Modules\Payments\Domain\Enums\RemotePaymentStatus;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * What the provider created in response to a PaymentIntentRequest.
 *
 * A decline is a result here, not an exception: the integration worked exactly
 * as intended and the answer was "no". Only a broken integration throws.
 *
 * @immutable
 */
final readonly class PaymentIntentResult
{
    /**
     * @param  string|null  $clientSecret  Never persisted and never logged — it is a bearer credential for this
     *                                     payment, and it exists on this object only to be handed to the browser.
     * @param  array<string, mixed>  $metadata  Provider response detail, safe to store once redacted.
     */
    public function __construct(
        public string $reference,
        public RemotePaymentStatus $status,
        public Money $amount,
        public ?string $clientSecret = null,
        public ?string $nextActionUrl = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $metadata = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status->isSuccessful();
    }

    public function requiresCustomerAction(): bool
    {
        return $this->status === RemotePaymentStatus::RequiresAction;
    }
}
