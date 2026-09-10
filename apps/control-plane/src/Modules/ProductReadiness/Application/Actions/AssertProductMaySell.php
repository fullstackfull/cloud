<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Application\Actions;

use Lynomia\Modules\ProductReadiness\Application\Services\ProductSellability;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Exceptions\ProductNotSellable;

/**
 * The guard between the readiness ladder and a new sale.
 *
 * In production, a product is sold only while its row says ready_to_sell —
 * which is a person's standing declaration on top of real, enabled,
 * discovered providers, withdrawn automatically the moment any of that goes.
 * So when a WP Toolkit licence expires the installer's readiness falls, the
 * WordPress product falls with it in the same transaction, and the next
 * order for a WordPress site is refused here. Sites already installed are
 * not touched: this guard is consulted by the actions that CREATE a sale
 * and by nothing that renews, serves or terminates one.
 *
 * Outside production the guard stands aside. Every other environment exists
 * to rehearse the sale against controlled providers that can never reach
 * ready_to_sell, and a guard that refused there would refuse every test and
 * every staging walk-through of the checkout.
 *
 * The decision itself lives in {@see ProductSellability}, which the catalogue
 * reads too: what this guard would refuse, the catalogue does not offer.
 */
final readonly class AssertProductMaySell
{
    public function __construct(
        private ProductSellability $sellability,
    ) {}

    /**
     * @throws ProductNotSellable
     */
    public function execute(Product $product): void
    {
        if ($this->sellability->maySell($product)) {
            return;
        }

        throw ProductNotSellable::because($product, $this->sellability->state($product));
    }
}
