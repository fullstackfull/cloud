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
 *
 * Optional capabilities are the ones the product's code calls only when the
 * provider reports them — file-level restore on a backup provider, staging
 * on a WordPress installer, redemption on a registrar. They are consumed
 * (so the capability gate counts them) and they are asked about (so the
 * screen can offer or withhold the feature), and their absence is not a
 * blocker on the product.
 */
final readonly class Requirement
{
    /**
     * @param  list<string>  $capabilities  Required: the product does not work without them.
     * @param  bool  $shared  Whether every product carries this requirement (payment, email)
     *                        rather than this one product asking for it.
     * @param  list<string>  $optional  Consumed when reported, never required.
     */
    public function __construct(
        public ProviderCategory $category,
        public array $capabilities,
        public bool $shared = false,
        public array $optional = [],
    ) {}
}
