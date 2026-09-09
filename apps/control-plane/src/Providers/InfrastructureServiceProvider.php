<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Console\Domain\Contracts\ConsoleUpstreamResolver;
use Lynomia\Modules\Console\Infrastructure\Upstream\ProviderConsoleUpstreamResolver;
use Lynomia\Modules\Dedicated\Application\Handlers\ProvisionDedicatedHandler;
use Lynomia\Modules\Dedicated\Application\Handlers\ReinstallDedicatedHandler;
use Lynomia\Modules\Dedicated\Domain\Contracts\HostReachability;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedReinstallLedger;
use Lynomia\Modules\Dedicated\Infrastructure\Reachability\TcpHostReachability;
use Lynomia\Modules\Infrastructure\Domain\Contracts\DeploymentController;
use Lynomia\Modules\Infrastructure\Infrastructure\Deployment\DeploymentControllerFactory;
use Lynomia\Modules\Provisioning\Domain\Contracts\DestructiveOperationLedger;
use Lynomia\Modules\Provisioning\Domain\Contracts\HandlerRegistry;
use Lynomia\Modules\Provisioning\Domain\Contracts\ResourceReservationReleaser;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Registries\ProvisioningHandlerRegistry;
use Lynomia\Modules\SharedHosting\Application\Handlers\ChangeHostingPackageHandler;
use Lynomia\Modules\SharedHosting\Application\Handlers\CreateHostingAccountHandler;
use Lynomia\Modules\SharedHosting\Application\Handlers\InstallWordPressHandler;
use Lynomia\Modules\SharedHosting\Domain\Contracts\SiteProbe;
use Lynomia\Modules\SharedHosting\Infrastructure\Probes\FakeSiteProbe;
use Lynomia\Modules\SharedHosting\Infrastructure\Probes\HttpSiteProbe;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use Lynomia\Modules\Vps\Application\Handlers\DestroyVpsHandler;
use Lynomia\Modules\Vps\Application\Handlers\ReinstallVpsHandler;
use Lynomia\Modules\Vps\Application\Handlers\ResizeVpsHandler;
use Lynomia\Modules\Vps\Application\Handlers\RestartVpsHandler;
use Lynomia\Modules\Vps\Application\Handlers\StartVpsHandler;
use Lynomia\Modules\Vps\Application\Handlers\StopVpsHandler;
use Lynomia\Modules\Vps\Infrastructure\IpamReservationReleaser;
use Lynomia\Modules\Vps\Infrastructure\NodeCapacityReleaser;
use Lynomia\Modules\Vps\Infrastructure\VpsReinstallLedger;
use Lynomia\Support\Provisioning\EveryDestructiveOperationLedger;
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
        // The bridge to the machines. Bound, not singleton: the fake reads
        // the environment at construction and refuses production there, and
        // that refusal must happen on every resolution.
        $this->app->bind(DeploymentController::class, static fn ($app): DeploymentController => (new DeploymentControllerFactory(
            (string) config('infrastructure.controller.driver', 'fake'),
            (string) $app->environment(),
            config('infrastructure.controller.iac_path'),
            getenv('CI') !== false && getenv('CI') !== '',
        ))->make());

        /*
         * How the platform checks that a rebuilt physical machine came back.
         *
         * Bound to a plain TCP connect, which is the only in-band check the
         * platform is entitled to make: it holds no key to a customer's
         * machine and should not. A deployment whose machines are only
         * reachable through a bastion binds something that knows how.
         */
        $this->app->bind(HostReachability::class, TcpHostReachability::class);

        /*
         * Who looks at a customer's site to decide whether it is really there.
         *
         * The fake is chosen by configuration and refuses to construct in
         * production, so a deployment that reaches for it by mistake fails
         * loudly rather than reporting every site as healthy.
         */
        $this->app->bind(SiteProbe::class, static fn ($app): SiteProbe => match (
            (string) config('hosting.wordpress.probe', 'http')
        ) {
            'fake' => new FakeSiteProbe,
            default => new HttpSiteProbe,
        });

        /*
         * The console gateway resolves its upstream through the machine's own
         * cluster. There is deliberately no alternative binding for a
         * "default" console host: a platform running two clusters would dial
         * the wrong one, and a console dialled at the wrong cluster is either
         * a failure or a connection to somebody else's machine with the same
         * id.
         */
        $this->app->bind(ConsoleUpstreamResolver::class, ProviderConsoleUpstreamResolver::class);

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
         * The same composition, for the question an operator retry has to ask
         * before it does anything: has this job already destroyed something?
         * Both rebuildable things answer for themselves, and the engine keeps
         * knowing about neither.
         */
        $this->app->bind(DestructiveOperationLedger::class, static fn ($app) => new EveryDestructiveOperationLedger([
            $app->make(VpsReinstallLedger::class),
            $app->make(DedicatedReinstallLedger::class),
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
        $handlers->register(ReinstallVpsHandler::class, ProvisioningJobKind::ReinstallVps);
        $handlers->register(DestroyVpsHandler::class, ProvisioningJobKind::DestroyVps);
        $handlers->register(ResizeVpsHandler::class, ProvisioningJobKind::Resize);
        $handlers->register(CreateHostingAccountHandler::class, ProvisioningJobKind::CreateHostingAccount);
        $handlers->register(InstallWordPressHandler::class, ProvisioningJobKind::InstallWordPress);
        $handlers->register(ChangeHostingPackageHandler::class, ProvisioningJobKind::ChangeHostingPackage);
        $handlers->register(ProvisionDedicatedHandler::class, ProvisioningJobKind::ProvisionDedicated);
        $handlers->register(ReinstallDedicatedHandler::class, ProvisioningJobKind::ReinstallDedicated);
    }
}
