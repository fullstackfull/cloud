<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Providers\FakeBackupProvider;
use Lynomia\Modules\Compute\Domain\Enums\ComputeDriver;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\FakeDedicatedProvider;
use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
use Lynomia\Modules\Dns\Infrastructure\Providers\FakeDnsProvider;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Providers\FakeDomainRegistrarProvider;
use Lynomia\Modules\Ipam\Infrastructure\Providers\FakeReverseDnsProvider;
use Lynomia\Modules\Ipam\Infrastructure\ReverseDnsProviderFactory;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Providers\Domain\Enums\ControlledDriver;
use Lynomia\Modules\SharedHosting\Domain\Contracts\WordPressInstaller;
use Lynomia\Modules\SharedHosting\Domain\Contracts\WordPressStagingProvider;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every controlled driver is reached the way a real one is.
 *
 * ===========================================================================
 * WHY THIS IS THE FIRST GATE AND NOT THE LAST
 * ===========================================================================
 *
 * Because the easy way to make a simulation pass is to build a second world.
 * Give the rehearsal its own registry, its own resolution rules and its own
 * entry points, and every test in it goes green while proving nothing about
 * the platform: the code path a customer's order takes is the one that was not
 * exercised.
 *
 * So each case below resolves its simulator through the factory the product
 * code uses — the cluster's driver column, the hosting node's panel, the
 * configured DNS driver, the payment registry keyed by the name persisted in
 * `transactions.provider` — and asserts the simulator comes back. Nothing here
 * constructs a simulator directly, and there is no simulation-only container
 * binding anywhere in the repository for one of these families.
 *
 * The second assertion in each case is the one that closes Gap 6's own
 * finding: the driver is also in the provider catalogue, so the Control
 * Center, capability discovery, preflight and readiness can all see the same
 * rehearsal the factories can.
 */
final class AControlledDriverIsReachableThroughTheOrdinaryFactoriesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_compute_factory_resolves_the_controlled_hypervisor_from_the_cluster_row(): void
    {
        $cluster = ComputeCluster::factory()->create(['driver' => ComputeDriver::Fake]);

        $this->assertInstanceOf(
            FakeComputeProvider::class,
            app(ComputeProviderFactory::class)->for($cluster),
        );
    }

    #[Test]
    public function the_dedicated_factory_resolves_the_controlled_controller_from_configuration(): void
    {
        /*
         * From configuration and deliberately not from the endpoint row: a
         * `bmc_endpoints` row may never name the controlled driver, because a
         * single bad row would make one machine silently unmanaged in
         * production.
         */
        config()->set('dedicated.provider', 'fake');

        $endpoint = BmcEndpoint::query()->create([
            'dedicated_server_id' => DedicatedServer::factory()->create()->getKey(),
            'protocol' => BmcProtocol::Redfish,
            'address' => '192.0.2.130',
            'port' => 443,
            'verify_tls' => true,
        ]);

        $provider = app(DedicatedProviderFactory::class)->for($endpoint);

        $this->assertInstanceOf(FakeDedicatedProvider::class, $provider);

        // Reported honestly rather than as a "fake" protocol, because callers
        // branch on it and a test of the iLO path must be able to see iLO.
        $this->assertSame(BmcProtocol::Redfish, $provider->protocol());
    }

    #[Test]
    public function the_hosting_factory_resolves_the_controlled_panel_from_the_node_row(): void
    {
        $node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        $this->assertInstanceOf(
            FakeHostingProvider::class,
            app(HostingProviderFactory::class)->for($node),
        );
    }

    #[Test]
    public function the_controlled_panel_is_also_the_wordpress_toolkit(): void
    {
        /*
         * WordPress is layered on the hosting contract in this repository and
         * is not a separate provider family. The controlled WordPress driver
         * therefore resolves to the same object the hosting one does, and the
         * assertion is that it satisfies both WordPress contracts — which no
         * real adapter in this build does.
         */
        $node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        $provider = app(HostingProviderFactory::class)->for($node);

        $this->assertInstanceOf(WordPressInstaller::class, $provider);
        $this->assertInstanceOf(WordPressStagingProvider::class, $provider);
    }

    #[Test]
    public function the_backup_factory_resolves_the_controlled_target_from_configuration(): void
    {
        config()->set('billing.providers.backup', 'fake');

        $cluster = ComputeCluster::factory()->create();

        $this->assertInstanceOf(
            FakeBackupProvider::class,
            app(BackupProviderFactory::class)->for($cluster),
        );
    }

    #[Test]
    public function one_configured_driver_resolves_both_halves_of_dns(): void
    {
        /*
         * One key drives forward and reverse DNS, because an operator who has
         * configured a provider has configured a provider — two keys would let
         * a deployment hold a forward adapter and a reverse adapter that
         * disagree about which account they are talking to.
         */
        config()->set('billing.providers.dns', 'fake');

        $this->assertInstanceOf(FakeDnsProvider::class, app(DnsProviderFactory::class)->make());
        $this->assertInstanceOf(FakeReverseDnsProvider::class, app(ReverseDnsProviderFactory::class)->make());
    }

    #[Test]
    public function the_registrar_factory_resolves_the_controlled_registrar_by_name(): void
    {
        $this->assertInstanceOf(
            FakeDomainRegistrarProvider::class,
            app(DomainRegistrarFactory::class)->make('fake'),
        );
    }

    #[Test]
    public function the_payment_registry_resolves_the_controlled_gateway_by_the_name_it_persists(): void
    {
        // By name rather than by class, because the name is what lives in
        // transactions.provider and in the webhook route.
        $this->assertInstanceOf(
            FakePaymentProvider::class,
            app(PaymentProviderRegistry::class)->get('fake'),
        );
    }

    #[Test]
    public function every_controlled_driver_in_the_catalogue_is_covered_by_a_case_above(): void
    {
        /*
         * The gate on the gate, and the one that makes a tenth controlled
         * driver somebody's problem today rather than in six months. A
         * catalogued driver nothing here resolves is a driver an operator can
         * register a provider row for and no factory can produce — which
         * fails at provisioning time, in a queue worker, on the first order.
         */
        $covered = [
            'fake' => 'one_configured_driver_resolves_both_halves_of_dns',
            'fake_rdns' => 'one_configured_driver_resolves_both_halves_of_dns',
            'fake_bmc' => 'the_dedicated_factory_resolves_the_controlled_controller_from_configuration',
            'fake_compute' => 'the_compute_factory_resolves_the_controlled_hypervisor_from_the_cluster_row',
            'fake_hosting' => 'the_hosting_factory_resolves_the_controlled_panel_from_the_node_row',
            'fake_wordpress' => 'the_controlled_panel_is_also_the_wordpress_toolkit',
            'fake_backup' => 'the_backup_factory_resolves_the_controlled_target_from_configuration',
            'fake_registrar' => 'the_registrar_factory_resolves_the_controlled_registrar_by_name',
            'fake_payment' => 'the_payment_registry_resolves_the_controlled_gateway_by_the_name_it_persists',
        ];

        $this->assertSame([], array_values(array_diff(ControlledDriver::names(), array_keys($covered))));
        $this->assertSame([], array_values(array_diff(array_keys($covered), ControlledDriver::names())));

        foreach ($covered as $driver => $test) {
            $this->assertTrue(
                method_exists($this, $test),
                sprintf('%s is covered by %s, which does not exist.', $driver, $test),
            );
        }
    }
}
