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
}
