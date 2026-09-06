<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\DTOs;

use Lynomia\Modules\Payments\Domain\Enums\RemotePaymentStatus;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * The provider's current view of a payment, fetched on demand.
 *
 * This is the only trusted alternative to a verified webhook. A browser coming
 * back from a redirect proves nothing — the URL is under the customer's
 * control — so the return handler asks the provider directly instead of
 * believing the query string.
 *
 * @immutable
 */
final readonly class RemotePaymentState
{
    /**
     * @param  string|null  $chargeReference  The refundable object, which for most providers is not the same
     *                                        identifier as the intent.
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $reference,
        public RemotePaymentStatus $status,
        public Money $amount,
        public ?string $chargeReference = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $metadata = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status->isSuccessful();
    }
}
