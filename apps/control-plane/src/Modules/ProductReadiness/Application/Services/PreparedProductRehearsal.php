<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Application\Services;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductSoftwareState;

/**
 * May the controlled simulation walk this prepared product's sale?
 *
 * A prepared product is finished software outside the approved launch scope:
 * the lifecycle is built, tested and reviewed, and no production-capable
 * provider adapter exists for what it requires. {@see ProductSellability}
 * therefore refuses to sell it anywhere. That refusal, applied without
 * exception, would also stop every test and every staging walk-through of
 * those lifecycles — the domain registration whole-life suite, the WordPress
 * ordering suite — and software nothing exercises rots quietly until the day
 * someone needs it.
 *
 * So the rehearsal is carved out explicitly, in one place, and bounded three
 * ways:
 *
 *   - **Never in production.** The environment check is first and answers
 *     false with nothing else consulted. No configuration value, no readiness
 *     row and no Admin declaration reaches past it;
 *   - **Only for `prepared` products.** A `readiness_only` product has no
 *     software to rehearse, so naming one here does nothing;
 *   - **Only by name.** A product is rehearsed because the configuration says
 *     so, not because it happens to be prepared.
 *
 * What this is not: a way to make something sellable. It permits a rehearsal
 * of a sale against controlled providers in an environment that cannot take
 * a customer's money. The product's real-provider status is untouched, it
 * remains outside the launch scope, and in production it cannot be bought.
 */
final readonly class PreparedProductRehearsal
{
    public function __construct(
        private Application $app,
    ) {}

    public function includes(Product $product): bool
    {
        if ($this->app->environment('production')) {
            return false;
        }

        if ($product->softwareState() !== ProductSoftwareState::Prepared) {
            return false;
        }

        return in_array($product->value, $this->named(), true);
    }

    /**
     * @return list<string>
     */
    private function named(): array
    {
        $configured = config('product_readiness.rehearsed_products', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter($configured, 'is_string'));
    }
}
