<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Dedicated\Application\Handlers\ProvisionDedicatedHandler;
use Lynomia\Modules\Provisioning\Domain\Contracts\HandlerRegistry;
use Lynomia\Modules\Provisioning\Domain\Contracts\ResourceReservationReleaser;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Registries\ProvisioningHandlerRegistry;
use Lynomia\Modules\SharedHosting\Application\Handlers\CreateHostingAccountHandler;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use Lynomia\Modules\Vps\Application\Handlers\RestartVpsHandler;
use Lynomia\Modules\Vps\Application\Handlers\StartVpsHandler;
use Lynomia\Modules\Vps\Application\Handlers\StopVpsHandler;
use Lynomia\Modules\Vps\Infrastructure\IpamReservationReleaser;
use Lynomia\Modules\Vps\Infrastructure\NodeCapacityReleaser;
use Lynomia\Support\Provisioning\EveryReservationReleaser;

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
         * Compensation reaches every scarce resource a build takes, not one of
         * them. The engine decides WHETHER to release or quarantine — that
         * follows from the failure class and belongs to the engine — while
         * each adapter decides what those words mean for the thing it
         * allocated.
         *
         * This was a single binding to IPAM, and the effect was that a failed
         * build handed back its address and kept the node's cpu, memory and
         * storage for ever. ReleaseNodeCapacity had been written for exactly
         * this and had no caller; there was only ever room for one releaser.
         *
         * The two adapters point opposite ways on quarantine, deliberately. An
         * address that may be in use is held OUT of the pool; capacity that may
         * be in use is held AS committed. Both are "do not let anyone else have
         * this until a person has looked".
         */
        $this->app->bind(ResourceReservationReleaser::class, static fn ($app) => new EveryReservationReleaser([
            $app->make(IpamReservationReleaser::class),
            $app->make(NodeCapacityReleaser::class),
        ]));

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

        /*
         * Registered as class strings with their kind stated, so that
         * registration does not construct every handler — and therefore every
         * provider client — on every request that touches the container.
         *
         * Every kind any production code path can create must appear here.
         * For most of this project's life only CreateVps did, and the effect
         * was not a compile error or a failing test: a customer pressing
         * "reboot", ordering shared hosting, or buying a dedicated server got
         * a 202, a job row, and a queued job that died at the worker with
         * HandlerNotRegisteredException. The handlers had been written; the
         * suites registered them themselves and passed. HandlerCoverageTest
         * now derives the required set from the code that creates jobs, so
         * this list cannot fall behind again.
         */
        $handlers->register(CreateVpsHandler::class, ProvisioningJobKind::CreateVps);
        $handlers->register(StartVpsHandler::class, ProvisioningJobKind::Start);
        $handlers->register(StopVpsHandler::class, ProvisioningJobKind::Stop);
        $handlers->register(RestartVpsHandler::class, ProvisioningJobKind::Restart);
        $handlers->register(CreateHostingAccountHandler::class, ProvisioningJobKind::CreateHostingAccount);
        $handlers->register(ProvisionDedicatedHandler::class, ProvisioningJobKind::ProvisionDedicated);
    }
}
