<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\DTOs;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Everything a coupon has to be judged against.
 *
 * Carried as one value rather than six arguments because validation and
 * redemption must be judging the same basket: the redemption re-runs the whole
 * validation under a row lock, and it can only be the same decision if it is
 * handed the identical inputs.
 *
 * @immutable
 */
final readonly class CouponContext
{
    /**
     * The instant the coupon is being judged at.
     *
     * Fixed once, at construction, so the validity window, the redemption
     * timestamp and any later reconstruction of the decision all refer to the
     * same moment — a checkout that straddles a coupon's expiry must not be
     * accepted by one check and refused by the next.
     */
    public CarbonImmutable $at;

    /**
     * @param  Money  $orderAmount  the discountable order value, before tax
     * @param  list<string>  $planIds  every plan on the order
     * @param  list<ProductKind>  $productKinds  every product kind on the order
     */
    public function __construct(
        public Customer $customer,
        public Money $orderAmount,
        public array $planIds = [],
        public array $productKinds = [],
        ?DateTimeInterface $at = null,
    ) {
        $this->at = $at === null ? CarbonImmutable::now() : CarbonImmutable::instance($at);
    }

    public function currency(): string
    {
        return $this->orderAmount->currency();
    }
}
