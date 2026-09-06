<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Provisioning\Domain\Contracts\HandlerRegistry;
use Lynomia\Modules\Provisioning\Domain\Contracts\ResourceReservationReleaser;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Registries\ProvisioningHandlerRegistry;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use Lynomia\Modules\Vps\Infrastructure\IpamReservationReleaser;

/**
 * Where the provisioning engine meets the things it provisions.
 *
 * The engine is provider-agnostic by construction: it imports nothing from
 * Compute, nothing from IPAM, and knows only two interfaces — a handler that
 * does the work for a kind of job, and a releaser that undoes reservations when
 * one fails. Both are bound here, which is the only file that has to know both
 * halves exist.
 *
 * Keeping the wiring in one place is what makes the boundary real rather than
 * aspirational. A handler that reached into IPAM directly would compile fine and
 * would quietly make the engine depend on address management forever.
 */
final class InfrastructureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProvisioningHandlerRegistry::class);
        $this->app->bind(HandlerRegistry::class, ProvisioningHandlerRegistry::class);

        /*
         * Compensation is bound to IPAM. The engine decides WHETHER to release
         * or quarantine — that decision follows from the failure class and
         * belongs to the engine — while this adapter decides what those words
         * mean for an IP address.
         */
        $this->app->bind(ResourceReservationReleaser::class, IpamReservationReleaser::class);

        /*
         * There is deliberately no global ComputeProvider binding. The driver
         * is a property of the cluster a machine lives on, recorded on its row,
         * so callers resolve through ComputeProviderFactory::for($cluster).
         * A single default would be wrong the moment a platform runs two
         * clusters on different hypervisors — which is exactly what a migration
         * looks like.
         */
    }

    public function boot(): void
    {
        /** @var ProvisioningHandlerRegistry $handlers */
        $handlers = $this->app->make(ProvisioningHandlerRegistry::class);

        // Registered as class strings with their kind stated, so that
        // registration does not construct every handler — and therefore every
        // provider client — on every request that touches the container.
        $handlers->register(CreateVpsHandler::class, ProvisioningJobKind::CreateVps);
    }
}
