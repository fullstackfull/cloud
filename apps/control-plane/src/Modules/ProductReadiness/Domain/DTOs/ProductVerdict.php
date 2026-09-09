<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\DTOs;

use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;

/**
 * Where a product stands, and the one thing in the way of the next rung.
 *
 * The engine never returns ready_to_sell: that rung is a person's
 * declaration, applied by the action that persists this verdict.
 */
final readonly class ProductVerdict
{
    /**
     * @param  list<RequirementVerdict>  $requirements
     * @param  array<string, string>  $dependencies  Dependency product => its state.
     */
    public function __construct(
        public Product $product,
        public ProductReadinessState $state,
        public array $requirements,
        public array $dependencies,
        public ?BlockerReason $blocker,
        public ?string $detail,
    ) {}
}
