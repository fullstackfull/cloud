<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Application\Listeners;

use Lynomia\Modules\ProductReadiness\Application\Actions\AssessAllProducts;
use Lynomia\Modules\Providers\Domain\Events\ProviderReadinessChanged;

/**
 * Blocker propagation.
 *
 * A credential revoked, a licence lapsed, a provider disabled: each moves a
 * provider's readiness, and each therefore moves every product that needs
 * that provider — in the same transaction, so there is no window in which a
 * product reads sellable on a provider that has just stopped answering.
 *
 * All products rather than the ones in the category, because the shared
 * requirements (payment, email) reach every product and the dependency edges
 * reach across categories; the sweep is seven evaluations from one query.
 */
final readonly class ReassessProductsWhenAProviderChanges
{
    public function __construct(
        private AssessAllProducts $assess,
    ) {}

    public function handle(ProviderReadinessChanged $event): void
    {
        $this->assess->execute();
    }
}
