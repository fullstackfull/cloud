<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Infrastructure\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Provisioning\Domain\Contracts\HandlerRegistry;
use Lynomia\Modules\Provisioning\Domain\Contracts\ResourceReservationReleaser;
use Lynomia\Modules\Provisioning\Infrastructure\Registries\ProvisioningHandlerRegistry;

/**
 * Wiring for the provisioning engine.
 *
 * The registry is a singleton because it memoises resolved handlers, and a
 * handler holds a configured client: a fresh registry per job would rebuild an
 * HTTP stack for every machine the platform touches.
 *
 * Note what is deliberately NOT bound here.
 *
 *  - No handlers. This module is provider-agnostic: it knows that work has
 *    kinds, not that Proxmox or cPanel exist. The application registers the
 *    handlers it has, which is what makes adding a platform an additive change
 *    rather than an edit to the engine.
 *
 *  - No {@see ResourceReservationReleaser}. There is no safe default: a
 *    no-op implementation would silently leak every address a failed build
 *    reserved, and a fake one would quietly release addresses that a timeout
 *    said must be held. An unbound interface fails loudly the first time
 *    compensation runs, which is the correct behaviour for a deployment that
 *    forgot to connect the engine to IPAM.
 */
final class ProvisioningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProvisioningHandlerRegistry::class);

        // Bound through a closure rather than as an alias so that resolving
        // either name yields the one memoised registry, without the container
        // resolving one into the other and back again.
        $this->app->singleton(
            HandlerRegistry::class,
            static fn (Application $app): ProvisioningHandlerRegistry => $app->make(ProvisioningHandlerRegistry::class),
        );
    }
}
