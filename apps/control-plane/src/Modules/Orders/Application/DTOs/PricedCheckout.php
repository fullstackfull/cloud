<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\DTOs;

use Illuminate\Database\Eloquent\Collection;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricedOrder;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Billing\Domain\ValueObjects\TaxRate;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;

/**
 * A priced basket: what it costs, what it is made of, and why.
 *
 * The order path persists it; the quote path serialises it. Both read the
 * same figures from the same object, which is what stops a quote and an
 * invoice from disagreeing.
 */
final readonly class PricedCheckout
{
    /**
     * @param  Collection<string, Plan>  $plans
     * @param  list<PricingLine>  $pricingLines
     */
    public function __construct(
        public Collection $plans,
        public array $pricingLines,
        public TaxRate $taxRate,
        public ?Coupon $coupon,
        public PricedOrder $priced,
        public PricedOrder $renewal,
    ) {}
}
