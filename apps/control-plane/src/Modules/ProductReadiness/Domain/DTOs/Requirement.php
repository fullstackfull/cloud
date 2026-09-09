<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\DTOs;

use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;

/**
 * One thing a product needs from a provider: a category, and the capabilities
 * in that category the product actually calls.
 *
 * The capabilities are the ones the product's own code invokes, not the
 * category's whole question set. A VPS product that never resizes does not
 * become unsellable because the compute provider cannot resize.
 */
final readonly class Requirement
{
    /**
     * @param  list<string>  $capabilities
     * @param  bool  $shared  Whether every product carries this requirement (payment, email)
     *                        rather than this one product asking for it.
     */
    public function __construct(
        public ProviderCategory $category,
        public array $capabilities,
        public bool $shared = false,
    ) {}
}
