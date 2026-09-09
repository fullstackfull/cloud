<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\DTOs;

use Lynomia\Modules\Domains\Domain\Enums\RedemptionSupport;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * What the platform can say about recovering one name, before any money
 * moves: whether it can, and at what list price. The price here is the
 * catalogue's, in minor units; the authoritative number a customer pays is
 * the quote they are issued, which is written from the same catalogue row
 * at the moment they ask.
 */
final readonly class RedemptionAnswer
{
    public function __construct(
        public RedemptionSupport $support,
        public string $reason,
        public ?Money $price,
    ) {}

    public static function available(Money $price): self
    {
        return new self(RedemptionSupport::Supported, 'The registrar can recover this name and the penalty is known.', $price);
    }

    public static function unavailable(RedemptionSupport $support, string $reason): self
    {
        return new self($support, $reason, null);
    }
}
