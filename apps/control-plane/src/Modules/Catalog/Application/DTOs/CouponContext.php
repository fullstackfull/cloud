<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\DTOs;

use BackedEnum;
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
     * Every plan on the order, as ids.
     *
     * @var list<string>
     */
    public array $planIds;

    /**
     * Every product kind on the order, as the string value of ProductKind.
     *
     * Kinds are carried as strings rather than as enum cases because the value
     * that reaches here is whatever the basket happens to hold — a checkout
     * reads it out of the catalogue and may hand it over already flattened.
     * A value the enum does not recognise is kept verbatim rather than
     * discarded: an unknown kind must still fail to match a restricted
     * campaign, and dropping it would silently turn "VPS plans only" into
     * "anything at all".
     *
     * @var list<string>
     */
    public array $productKinds;

    /**
     * @param  Money  $orderAmount  the discountable order value, before tax
     * @param  list<string>  $planIds  every plan on the order
     * @param  list<ProductKind|BackedEnum|string>  $productKinds  every product kind on the order
     */
    public function __construct(
        public Customer $customer,
        public Money $orderAmount,
        array $planIds = [],
        array $productKinds = [],
        ?DateTimeInterface $at = null,
    ) {
        $this->planIds = self::normalizeStrings($planIds);
        $this->productKinds = self::normalizeStrings($productKinds);
        $this->at = $at === null ? CarbonImmutable::now() : CarbonImmutable::instance($at);
    }

    public function currency(): string
    {
        return $this->orderAmount->currency();
    }

    /**
     * The same basket, judged at a different instant.
     *
     * Redemption uses this to re-judge the validity window against the moment
     * the coupon is actually consumed rather than against the moment the
     * customer was shown a preview — the two can be minutes or days apart, and
     * only the second one is the moment the campaign is being spent.
     */
    public function judgedAt(DateTimeInterface $at): self
    {
        return new self(
            customer: $this->customer,
            orderAmount: $this->orderAmount,
            planIds: $this->planIds,
            productKinds: $this->productKinds,
            at: $at,
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function normalizeStrings(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $string = match (true) {
                $value instanceof BackedEnum => (string) $value->value,
                is_string($value) => $value,
                is_int($value) => (string) $value,
                default => '',
            };

            if ($string !== '') {
                $normalized[] = $string;
            }
        }

        return array_values(array_unique($normalized));
    }
}
