<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Domain\Enums\ComputeDriver;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Infrastructure\Application\Preflight\InfrastructurePreflightService;
use Lynomia\Modules\Infrastructure\Application\Reference\LoadReferenceTopologyForSimulation;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightMode;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightRequest;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightScope;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceKind;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceTopology;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceTopologyInvalid;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceTopologyValidator;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\SecretFixtures;
use Tests\TestCase;

/**
 * The reference estate: valid, coherent, loadable, and never mistakeable for
 * an inventory.
 *
 * ===========================================================================
 * WHY EVERY REFUSAL BELOW HAS A POSITIVE TWIN
 * ===========================================================================
 *
 * A validator that refuses everything passes every negative test and is
 * useless. So each gate is asserted twice: once that the shipped topology is
 * accepted, and once that a specific, minimal mutation of it is refused, with
 * the mutation being the only difference. A refusal that could also have been
 * produced by the mutation breaking something else is not evidence about the
 * gate, and the two cases in `an_orphan_is_refused_as_an_orphan` and
 * `a_dependency_may_not_claim_the_wrong_provider` are written the way they are
 * because the obvious version of each was refused for the wrong reason.
 */
final class TheReferenceTopologyIsAModelAndNotAnInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<mixed>
     */
    private function shipped(): array
    {
        /** @var array<mixed> $raw */
        $raw = require resource_path(ReferenceTopology::PATH);

        return $raw;
    }

    /**
     * @param  callable(array<mixed>): array<mixed>  $mutate
     * @return list<string>
     */
    private function violationsAfter(callable $mutate): array
    {
        return (new ReferenceTopologyValidator)->violations($mutate($this->shipped()));
    }

    // ---------------------------------------------------------------------
    // Schema and markers
    // ---------------------------------------------------------------------

    #[Test]
    public function the_shipped_topology_is_valid(): void
    {
        self::assertSame([], (new ReferenceTopologyValidator)->violations($this->shipped()));
    }

    #[Test]
    public function the_marker_says_non_production_in_a_field_rather_than_a_comment(): void
    {
        $raw = $this->shipped();

        self::assertSame('lynomia-reference-topology', $raw['kind']);
        self::assertSame('reference', $raw['environment']);
        self::assertFalse($raw['production']);
        self::assertFalse($raw['deployable']);
        self::assertFalse($raw['reachable']);

        $topology = ReferenceTopology::load();

        self::assertFalse($topology->isProduction());
        self::assertFalse($topology->isDeployable());
        self::assertFalse($topology->isReachable());
        self::assertSame('REFERENCE — NON-PRODUCTION', $topology->label());
    }

    #[Test]
    public function claiming_to_be_production_is_refused(): void
    {
        foreach (['production', 'deployable', 'reachable'] as $flag) {
            $violations = $this->violationsAfter(static function (array $raw) use ($flag): array {
                $raw[$flag] = true;

                return $raw;
            });

            self::assertNotSame([], $violations, sprintf('`%s: true` was accepted.', $flag));
            self::assertStringContainsString($flag, $violations[0]);
        }
    }

    #[Test]
    public function a_file_that_is_not_a_reference_topology_is_refused(): void
    {
        $violations = $this->violationsAfter(static function (array $raw): array {
            $raw['kind'] = 'lynomia-production-inventory';

            return $raw;
        });

        self::assertNotSame([], $violations);
    }

    // ---------------------------------------------------------------------
    // Graph integrity
    // ---------------------------------------------------------------------

    #[Test]
    public function every_reference_resolves_exactly_once(): void
    {
        $topology = ReferenceTopology::load();

        $resolved = 0;

        foreach ($topology->objects as $object) {
            foreach ($object->refs as $slot => $target) {
                if ($target === null) {
                    continue;
                }

                self::assertTrue(
                    $topology->has($target),
                    sprintf('%s.%s names %s, which does not exist.', $object->id, $slot, $target),
                );

                $expected = $object->kind->refSlots()[$slot]['kind'] ?? null;

                if ($expected !== null) {
                    self::assertSame($expected, $topology->get($target)->kind);
                }

                $resolved++;
            }
        }

        self::assertGreaterThan(40, $resolved, 'The estate barely references anything, so this proves little.');
    }

    #[Test]
    public function a_dangling_reference_is_refused(): void
    {
        $violations = $this->violationsAfter(static function (array $raw): array {
            $raw['objects']['node']['ref-node-alpha-1-a']['refs']['cluster'] = 'ref-cluster-that-went-away';

            return $raw;
        });

        self::assertNotSame([], $violations);
        self::assertStringContainsString('ref-cluster-that-went-away', $violations[0]);
    }

    #[Test]
    public function a_reference_to_the_wrong_kind_is_refused(): void
    {
        $violations = $this->violationsAfter(static function (array $raw): array {
            $raw['objects']['node']['ref-node-alpha-1-a']['refs']['cluster'] = 'ref-rack-alpha-1-a';

            return $raw;
        });

        self::assertNotSame([], $violations);
        self::assertStringContainsString('is a rack and not a cluster', $violations[0]);
    }

    /**
     * The orphan gate, isolated.
     *
     * Every kind but one has a required upward reference, so adding an object
     * with no refs at all is refused for the missing reference rather than for
     * floating — which is a different gate. A provider row is the only thing
     * whose single slot is optional, so it is the only object that can actually
     * be an orphan, and it is what this asserts.
     */
    #[Test]
    public function an_orphan_is_refused_as_an_orphan(): void
    {
        $violations = $this->violationsAfter(static function (array $raw): array {
            $raw['objects']['provider']['ref-provider-alpha-floating'] = [
                'facts' => [
                    'name' => 'ref-provider-alpha-floating',
                    'category' => 'dns',
                    'driver' => 'fake',
                    'environment' => 'development',
                    'endpoint' => 'fake://ref-floating',
                ],
                'refs' => [],
            ];

            return $raw;
        });

        self::assertSame(1, count($violations), implode(' | ', $violations));
        self::assertStringContainsString('not connected to any region', $violations[0]);
    }

    // ---------------------------------------------------------------------
    // Secrets
    // ---------------------------------------------------------------------

    #[Test]
    public function the_shipped_topology_contains_no_credential_field_at_all(): void
    {
        $forbidden = ['password', 'passwd', 'secret', 'token', 'api_key', 'private_key', 'authorization', 'username', 'credential', 'credentials'];

        foreach (ReferenceTopology::load()->objects as $object) {
            foreach (array_keys($object->flattened()) as $field) {
                self::assertNotContains(
                    strtolower(explode('.', $field)[0]),
                    $forbidden,
                    sprintf('%s carries a field called %s.', $object->id, $field),
                );
            }
        }
    }

    #[Test]
    public function a_credential_field_is_refused(): void
    {
        $violations = $this->violationsAfter(static function (array $raw): array {
            $raw['objects']['bmc']['ref-bmc-alpha-1-04']['facts']['password'] = 'anything at all';

            return $raw;
        });

        self::assertNotSame([], $violations);
        self::assertStringContainsString('password', $violations[0]);
    }

    /**
     * The fixture comes from SecretFixtures rather than being written here.
     *
     * A gate that refuses key-shaped values needs a key-shaped value to refuse,
     * and a literal one in this file would be caught by the CI step that fails
     * the build when a credential shape appears in tracked source — which it
     * was, on the first run of that step against this test. Both gates are
     * right, and the repository already resolved the conflict by location:
     * every credential-shaped literal lives in tests/Support/SecretFixtures.php,
     * which is the CI step's one declared exception.
     */
    #[Test]
    public function a_key_shaped_value_is_refused_whatever_the_field_is_called(): void
    {
        $violations = $this->violationsAfter(static function (array $raw): array {
            $raw['objects']['bmc']['ref-bmc-alpha-1-04']['facts']['vendor_class'] = SecretFixtures::PRIVATE_KEY_PEM;

            return $raw;
        });

        self::assertNotSame([], $violations);
        self::assertStringContainsString('shaped like a key', $violations[0]);
    }

    // ---------------------------------------------------------------------
    // Addresses: documentation only, in this direction
    // ---------------------------------------------------------------------

    #[Test]
    public function every_address_in_the_estate_is_a_documentation_address(): void
    {
        $topology = ReferenceTopology::load();
        $seen = 0;

        foreach ($topology->objects as $object) {
            if (! $object->kind->carriesAddresses()) {
                continue;
            }

            self::assertTrue($object->bool('reference_only'), $object->id.' carries an address without saying so.');
            $seen++;
        }

        self::assertSame(9, $seen, 'The number of address-carrying objects moved; check the new one states reference_only.');
    }

    #[Test]
    public function a_routable_address_is_refused(): void
    {
        $violations = $this->violationsAfter(static function (array $raw): array {
            $raw['objects']['bmc']['ref-bmc-alpha-1-04']['facts']['address'] = '8.8.8.8';

            return $raw;
        });

        self::assertNotSame([], $violations);
        self::assertStringContainsString('belong to nobody', $violations[0]);
    }

    #[Test]
    public function a_real_hostname_is_refused(): void
    {
        $violations = $this->violationsAfter(static function (array $raw): array {
            $raw['objects']['hosting_node']['ref-hosting-alpha-1']['facts']['hostname'] = 'node1.lynomia.com';

            return $raw;
        });

        self::assertNotSame([], $violations);
    }

    // ---------------------------------------------------------------------
    // Providers and dependencies
    // ---------------------------------------------------------------------

    #[Test]
    public function every_reference_provider_names_a_controlled_driver(): void
    {
        $controlled = (new ProviderCatalogue)->controlledDrivers();
        $topology = ReferenceTopology::load();
        $providers = $topology->of(ReferenceKind::Provider);

        self::assertNotSame([], $providers);

        foreach ($providers as $provider) {
            self::assertContains($provider->string('driver'), $controlled);
            self::assertNotSame('production', $provider->string('environment'));
        }
    }

    #[Test]
    public function a_real_driver_is_refused(): void
    {
        $violations = $this->violationsAfter(static function (array $raw): array {
            $raw['objects']['provider']['ref-provider-alpha-dns']['facts']['driver'] = 'cloudflare';

            return $raw;
        });

        self::assertNotSame([], $violations);
        self::assertStringContainsString('is a real driver', $violations[0]);
    }

    #[Test]
    public function a_reference_provider_declared_for_production_is_refused(): void
    {
        $violations = $this->violationsAfter(static function (array $raw): array {
            $raw['objects']['provider']['ref-provider-alpha-dns']['facts']['environment'] = 'production';

            return $raw;
        });

        self::assertNotSame([], $violations);
        self::assertStringContainsString('declared for production', $violations[0]);
    }

    /**
     * Retargeted rather than removed, so that the provider it used to point at
     * is still reachable and the orphan gate stays quiet. The first version of
     * this test moved the reference away and was refused for floating, which
     * proved nothing about the category rule.
     */
    #[Test]
    public function a_dependency_may_not_claim_the_wrong_provider(): void
    {
        $violations = $this->violationsAfter(static function (array $raw): array {
            $raw['objects']['dependency']['ref-dep-alpha-bmc']['refs']['satisfied_by'] = 'ref-provider-alpha-dns';

            return $raw;
        });

        self::assertSame(1, count($violations), implode(' | ', $violations));
        self::assertStringContainsString('is not a bmc provider', $violations[0]);
    }

    #[Test]
    public function a_dependency_no_reference_provider_can_satisfy_says_so_rather_than_looking_complete(): void
    {
        $topology = ReferenceTopology::load();

        $unsatisfiable = [];

        foreach ($topology->of(ReferenceKind::Dependency) as $dependency) {
            if ($dependency->ref('satisfied_by') === null) {
                $unsatisfiable[] = $dependency->string('requires');
            }
        }

        /*
         * Empty, and it was ['backup', 'registrar', 'payment'] until Gap 6.
         *
         * Those three were unsatisfiable for one reason: the catalogue had a
         * controlled driver for two categories, so a reference provider row
         * for a third named a driver the validator refused. The simulators
         * behind all three existed the whole time. Gap 6 catalogued them, the
         * rows now exist, and every dependency this estate declares names
         * something that can stand in for it.
         *
         * The assertion is kept at [] rather than deleted, because the shape
         * it guards still matters: a dependency nothing can satisfy has to
         * say so out loud rather than leaving a green report to imply
         * otherwise. A new dependency for a category with no controlled
         * driver fails here, which is the deliberate conversation.
         */
        self::assertSame([], $unsatisfiable);
    }

    // ---------------------------------------------------------------------
    // Monitoring
    // ---------------------------------------------------------------------

    #[Test]
    public function every_monitoring_target_names_a_collector_this_application_has(): void
    {
        $targets = ReferenceTopology::load()->of(ReferenceKind::MonitoringTarget);

        self::assertNotSame([], $targets);

        foreach ($targets as $target) {
            self::assertNotSame([], $target->strings('collectors'));
            self::assertNotNull($target->ref('observes'), $target->id.' observes nothing.');

            foreach ($target->strings('collectors') as $collector) {
                self::assertTrue(
                    class_exists('Lynomia\\Modules\\Monitoring\\Application\\Collectors\\'.$collector),
                    $collector.' does not exist.',
                );
            }
        }
    }

    #[Test]
    public function a_monitoring_target_naming_an_absent_collector_is_refused(): void
    {
        $violations = $this->violationsAfter(static function (array $raw): array {
            $raw['objects']['monitoring_target']['ref-monitor-alpha-1-backups']['facts']['collectors'] = ['NoSuchCollector'];

            return $raw;
        });

        self::assertNotSame([], $violations);
        self::assertStringContainsString('silence Gap 3 found', $violations[0]);
    }

    // ---------------------------------------------------------------------
    // Loading
    // ---------------------------------------------------------------------

    #[Test]
    public function loading_puts_a_coherent_estate_into_the_database(): void
    {
        $written = app(LoadReferenceTopologyForSimulation::class)->execute();

        self::assertSame(1, Region::count());
        self::assertSame(1, Datacenter::count());
        self::assertSame(3, ComputeNode::count());
        self::assertSame(5, ComputeStorage::count());
        self::assertSame(4, VmTemplate::count());
        self::assertSame(4, Network::count());
        self::assertSame(5, ManagedServer::count());
        self::assertSame(5, DedicatedServer::count());
        self::assertSame(1, BmcEndpoint::count());
        self::assertSame(1, HostingNode::count());
        self::assertSame(9, ProviderInstance::count());

        self::assertSame(3, $written['node']);
        self::assertSame(9, $written['provider']);

        // A /26 is 64 addresses; the network, the gateway and the broadcast
        // address are not allocatable, and the v6 prefix is delegated rather
        // than enumerated.
        self::assertSame(64, IpAddress::count());
    }

    #[Test]
    public function loading_is_idempotent(): void
    {
        $loader = app(LoadReferenceTopologyForSimulation::class);

        $loader->execute();
        $counts = [ComputeNode::count(), IpAddress::count(), ManagedServer::count(), ProviderInstance::count()];

        $loader->execute();

        self::assertSame($counts, [ComputeNode::count(), IpAddress::count(), ManagedServer::count(), ProviderInstance::count()]);
    }

    #[Test]
    public function nothing_it_loads_can_be_dialled_or_can_satisfy_production(): void
    {
        app(LoadReferenceTopologyForSimulation::class)->execute();

        foreach (ComputeCluster::all() as $cluster) {
            self::assertSame(ComputeDriver::Fake, $cluster->driver);
            self::assertStringStartsWith('fake://', (string) $cluster->api_endpoint);
            self::assertNull($cluster->credentials_reference);
        }

        foreach (HostingNode::all() as $node) {
            self::assertSame(HostingPanel::Fake, $node->panel);
            self::assertNull($node->credentials_reference);
            self::assertFalse($node->panel_licensed);
        }

        foreach (ProviderInstance::all() as $provider) {
            self::assertSame(DeploymentEnvironment::Development, $provider->environment);
            self::assertNull($provider->credential_reference_id);
            self::assertNull($provider->licence_id);
        }

        foreach (ManagedServer::all() as $machine) {
            self::assertSame(DeploymentEnvironment::Development, $machine->environment);
            self::assertNull($machine->credential_reference_id);
            self::assertFalse($machine->allow_reimage);
        }

        foreach (BmcEndpoint::all() as $bmc) {
            self::assertNull($bmc->username);
            self::assertNull($bmc->credentials_reference);
        }
    }

    #[Test]
    public function the_loader_refuses_a_topology_that_claims_to_be_deployable(): void
    {
        $raw = $this->shipped();
        $raw['deployable'] = true;

        $this->expectException(ReferenceTopologyInvalid::class);

        ReferenceTopology::fromArray($raw);
    }

    #[Test]
    public function the_loader_refuses_to_run_on_a_production_installation(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must never be loaded into a production installation/');

        app(LoadReferenceTopologyForSimulation::class)->execute();
    }

    // ---------------------------------------------------------------------
    // What the estate can represent (§43-§47)
    // ---------------------------------------------------------------------

    #[Test]
    public function the_estate_can_show_an_eligible_node_and_an_ineligible_one(): void
    {
        app(LoadReferenceTopologyForSimulation::class)->execute();

        $statuses = ComputeNode::query()->pluck('status')->map(static fn ($s): string => $s instanceof NodeStatus ? $s->value : (string) $s)->all();

        self::assertContains(NodeStatus::Active->value, $statuses);
        self::assertContains(NodeStatus::Maintenance->value, $statuses);

        // Different capacities, so that "chose the node with room" is
        // distinguishable from "chose the first node".
        $cores = ComputeNode::query()->pluck('cpu_cores')->all();
        self::assertGreaterThan(1, count(array_unique($cores)));
    }

    #[Test]
    public function the_estate_can_show_a_network_with_no_bridge_to_attach_to(): void
    {
        app(LoadReferenceTopologyForSimulation::class)->execute();

        self::assertSame(1, Network::query()->whereNull('bridge')->count());
        self::assertGreaterThan(1, Network::query()->whereNotNull('bridge')->count());
        self::assertGreaterThan(1, Network::query()->whereNotNull('vlan_id')->distinct()->count('vlan_id'));
    }

    #[Test]
    public function the_estate_can_show_an_installable_template_a_disabled_one_and_another_architecture(): void
    {
        app(LoadReferenceTopologyForSimulation::class)->execute();

        self::assertGreaterThan(0, VmTemplate::query()->where('is_active', true)->whereNotNull('provider_reference')->count());
        self::assertSame(1, VmTemplate::query()->where('is_active', false)->count());
        self::assertSame(1, VmTemplate::query()->whereNull('provider_reference')->count());
        self::assertSame(1, VmTemplate::query()->where('architecture', CpuArchitecture::Aarch64)->count());
        self::assertSame(1, VmTemplate::query()->where('requires_licence', true)->count());
    }

    #[Test]
    public function the_estate_can_show_a_machine_nothing_may_connect_to(): void
    {
        app(LoadReferenceTopologyForSimulation::class)->execute();

        self::assertSame(1, ManagedServer::query()->where('safety_class', SafetyClass::DoNotTouch)->count());
        self::assertGreaterThan(1, ManagedServer::query()->where('safety_class', '!=', SafetyClass::DoNotTouch->value)->count());

        foreach (ManagedServer::all() as $machine) {
            self::assertFalse(
                $machine->safety_class === SafetyClass::ReimageAllowed,
                $machine->name.' is reimage_allowed. No reference machine may be: it does not exist, and a reimage flag that lives permanently in an inventory is a default with extra steps.',
            );
        }
    }

    #[Test]
    public function the_estate_can_show_a_chassis_that_cannot_be_reserved(): void
    {
        app(LoadReferenceTopologyForSimulation::class)->execute();

        self::assertSame(1, DedicatedServer::query()->where('status', 'maintenance')->count());
        self::assertSame(4, DedicatedServer::query()->where('status', 'available')->count());
        self::assertGreaterThan(1, DedicatedServer::query()->distinct()->count('hardware_profile'));
    }

    #[Test]
    public function the_estate_can_show_a_backup_dependency_whose_verification_is_unknown(): void
    {
        $target = ReferenceTopology::load()->one(ReferenceKind::BackupTarget);

        self::assertSame('unknown', $target->string('verification'));
        self::assertNotSame('', $target->string('datastore'));
    }

    // ---------------------------------------------------------------------
    // Preflight (§31)
    // ---------------------------------------------------------------------

    #[Test]
    public function a_simulation_preflight_over_the_reference_estate_says_which_estate_it_looked_at(): void
    {
        app(LoadReferenceTopologyForSimulation::class)->execute();

        $report = app(InfrastructurePreflightService::class)
            ->run(new PreflightRequest(PreflightMode::Simulation, PreflightScope::Estate));

        self::assertTrue($report->referenceTopology);
        self::assertSame('REFERENCE TOPOLOGY', $report->topologyLabel());
        self::assertSame('SIMULATION', $report->mode->label());

        // A complete reference estate removes no real-validation requirement.
        self::assertSame([], $report->realVerificationClaims());
        self::assertFalse($report->passed());
        self::assertTrue($report->toArray()['reference_topology']);
    }

    /**
     * The positive twin: an estate that is not the reference one does not get
     * labelled as though it were. Without this, a report that always said
     * REFERENCE TOPOLOGY would pass the assertion above.
     */
    #[Test]
    public function a_preflight_over_an_estate_that_is_not_the_reference_one_says_so(): void
    {
        $report = app(InfrastructurePreflightService::class)
            ->run(new PreflightRequest(PreflightMode::Simulation, PreflightScope::Estate));

        self::assertFalse($report->referenceTopology);
        self::assertSame('CONFIGURED INFRASTRUCTURE', $report->topologyLabel());
    }
}
