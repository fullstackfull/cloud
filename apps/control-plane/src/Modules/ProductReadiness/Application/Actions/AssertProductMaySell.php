<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Application\Actions;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Exceptions\ProductNotSellable;
use Lynomia\Modules\ProductReadiness\Infrastructure\Models\ProductReadiness;

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
 * every staging walk-through of the checkout. That is the one environment
 * check in this module, and it is the same question the fake providers ask
 * before agreeing to exist.
 */
final readonly class AssertProductMaySell
{
    public function __construct(
        private Application $app,
    ) {}

    /**
     * @throws ProductNotSellable
     */
    public function execute(Product $product): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $row = ProductReadiness::query()->where('product', $product->value)->first();

        $reached = $row->state ?? ProductReadinessState::NotReady;

        if ($reached !== ProductReadinessState::ReadyToSell) {
            throw ProductNotSellable::because($product, $reached);
        }
    }
}
