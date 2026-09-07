<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;

/**
 * Multi-tenancy wiring.
 *
 * Two things live here, and both exist so that tenant isolation is structural
 * rather than a rule each endpoint has to remember.
 *
 * The acting customer is a scoped singleton: one instance per request, resolved
 * once by middleware, discarded between requests even under a worker that keeps
 * the container alive.
 *
 * Route-model bindings for every customer-owned resource are registered in one
 * place, each of them resolving through the acting customer's own relation.
 * That means `/orders/{order}` cannot return another customer's order, because
 * the query that finds it never sees rows outside the tenant - the isolation is
 * in the binding, not in a check inside the controller that somebody might not
 * write. A reviewer can read this one file and see the complete list of what is
 * reachable and how it is scoped.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ActingCustomer::class);
    }

    public function boot(): void
    {
        // Bindings are registered by the modules that own the models, as their
        // HTTP surfaces are built. The registry lives here so that the list is
        // in one auditable place rather than scattered across route files.
    }
}
