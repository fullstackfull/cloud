<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\DTOs;

use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * The provider's acknowledgement of a refund.
 *
 * @immutable
 */
final readonly class RemoteRefundResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $reference,
        public RefundStatus $status,
        public Money $amount,
        public ?string $failureReason = null,
        public array $metadata = [],
    ) {}
}
