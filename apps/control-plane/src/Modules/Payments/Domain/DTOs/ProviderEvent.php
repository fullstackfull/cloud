<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\DTOs;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * One provider webhook, normalised.
 *
 * A ProviderEvent only ever exists for a payload whose signature has already
 * been verified; the adapters do not expose a way to build one from raw input.
 * Everything downstream may therefore treat its fields as trusted, while the
 * raw payload it carries stays explicitly untrusted-shaped data that is only
 * stored, never interpreted a second time.
 *
 * @immutable
 */
final readonly class ProviderEvent
{
    /**
     * @param  string  $providerEventId  The provider's own id for this event. Together with the provider name
     *                                   it is the replay key, so it is required even for events we ignore.
     * @param  string  $type  The provider's own event type, kept verbatim for operators.
     * @param  string|null  $providerReference  The payment or refund this event concerns.
     * @param  array<string, mixed>  $payload  The verified raw payload.
     * @param  array<string, string>  $metadata  The metadata we attached when the payment was created.
     * @param  string|null  $refundReference  For an event about one refund: the provider's own id for that refund,
     *                                        the one IssueRefund stored as the refund row's provider_reference.
     * @param  RefundStatus|null  $refundStatus  For an event about one refund: where that refund now stands. The
     *                                           kind is RefundSucceeded for every refund event an adapter
     *                                           recognises; this, not the kind, says whether the refund went
     *                                           through, failed or is still pending at the provider.
     */
    public function __construct(
        public string $providerEventId,
        public string $type,
        public ProviderEventKind $kind,
        public ?Money $amount,
        public ?string $currency,
        public ?string $providerReference,
        public array $payload,
        public array $metadata = [],
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public ?CarbonImmutable $occurredAt = null,
        public ?string $refundReference = null,
        public ?RefundStatus $refundStatus = null,
    ) {}

    /**
     * The customer this payment was created for, as recorded in the metadata
     * we set ourselves at intent creation.
     */
    public function customerReference(): ?string
    {
        return $this->metadata['customer_id'] ?? null;
    }

    public function invoiceReference(): ?string
    {
        return $this->metadata['invoice_id'] ?? null;
    }
}
