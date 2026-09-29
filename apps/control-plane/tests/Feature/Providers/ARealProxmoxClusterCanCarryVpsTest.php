<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\ReinstallVmRequest;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\ProductReadiness\Application\Actions\AssessProduct;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProductVerdict;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProviderFacts;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\RequirementVerdict;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductReadinessEvaluator;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductRequirements;
use Lynomia\Modules\Providers\Application\Actions\TestConnection;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ControlledDriver;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderCapability;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Providers\Infrastructure\Testers\ProxmoxConnectionTester;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * F-13: a real Proxmox cluster, holding the role this repository's own
 * automation installs, can carry VPS — and nothing less than that role can.
 *
 * ===========================================================================
 * WHAT WAS WRONG
 * ===========================================================================
 *
 * VPS requires twelve compute capabilities, with no optional list, and the
 * Proxmox connection tester's privilege map settled only nine of them.
 * `reinstall` and `templates` were absent from the map, so every real test of
 * every real cluster recorded them Unknown for ever; `supports()` is false for
 * Unknown, so VPS could never reach ReadyForProduction on anything but the
 * simulator. It failed closed, which is why nobody was hurt by it, and it
 * meant the product the platform exists to sell could only ever be proven by
 * its own fake.
 *
 * ===========================================================================
 * WHAT THIS FILE PINS, AND WHERE EACH HALF LIVES
 * ===========================================================================
 *
 *  1. Every capability VPS requires of compute is settled by the tester —
 *     none of them is left Unknown by construction.
 *  2. Every privilege the map asks for is one the provisioning role in
 *     `infrastructure/ansible/group_vars/proxmox.yml` grants. The role is
 *     parsed, not restated, so a privilege added to the map and not to the
 *     role fails here rather than on the first real cluster.
 *  3. End to end, through the real tester and the real factory with only the
 *     wire faked: that role carries VPS's compute requirement to
 *     ReadyForProduction, and the token short of any one mapped privilege
 *     does not. The converse is pinned too: the controlled hypervisor with
 *     every capability Supported reaches only ReadyForTest.
 *  4. `inventory_sync`. A token short of `Datastore.Audit` reads no storage
 *     pools, so the inventory sync records none, and the scheduler — which
 *     places only on recorded pools — places nothing. Such a token used to be
 *     declared ready. It is now a capability in four places (the category,
 *     the Proxmox catalogue entry, the VPS requirement and the privilege map)
 *     and this file breaks if the four stop agreeing; the architecture gate
 *     cannot see all four removed together.
 *  5. The two premises the map rests on, each checked at source:
 *     - `create` and `reinstall` ask for `VM.Config.Cloudinit` because every
 *       call that builds or rebuilds a machine hands the adapter a
 *       cloud-init config. The cloud-init pin reads the handlers' argument
 *       lists token by token, so a comment or a string that merely quotes
 *       `cloudInit:` does not satisfy it.
 *     - `inventory_sync` is load-bearing because the inventory sync is the
 *       only production code that brings a ComputeStorage row into
 *       existence. The writer scan establishes that, and states exactly what
 *       it counts (below).
 *
 * ===========================================================================
 * WHAT THIS FILE DOES NOT REACH
 * ===========================================================================
 *
 *  - The last link of the end-to-end chain is asserted, not derived:
 *    {@see self::provenProxmox()} writes the provider-level readiness column,
 *    environment and state by hand, because a production row pointed at a
 *    `.test` endpoint is refused before it is ever tested. The capability
 *    table it is judged on is the real tester's answer.
 *  - A privilege held only at one path counts as held; the tester flattens
 *    scope. Recorded on the ledger, moot for this repository's clusters, whose
 *    automation grants the role at `/`.
 *  - `suspend` and `unsuspend` map to `VM.PowerMgmt` alone while the adapter
 *    also writes `onboot` and `lock` (`VM.Config.Options`). No verdict moves,
 *    because both compute products require `create`, which requires
 *    `VM.Config.Options`; the tester's answer for those two capabilities is
 *    still wrong for such a token, and that is recorded rather than fixed.
 *  - The cloud-init pin sees a call only when the method is written whole. A
 *    name assembled at run time is invisible to it; what stops that from
 *    removing cloud-init from the *last* machine-building call is the
 *    per-file backstop that at least one literal call site exists. So a
 *    hidden call site can add a machine without cloud-init; it cannot take
 *    cloud-init off the one the backstop sees.
 */
final class ARealProxmoxClusterCanCarryVpsTest extends TestCase
{
    use RefreshDatabase;

    private const string VARIABLE = 'LYNOMIA_TEST_F13_PROXMOX_TOKEN';

    private const string ROLE_FILE = __DIR__.'/../../../../../infrastructure/ansible/group_vars/proxmox.yml';

    private const string SRC = __DIR__.'/../../../src';

    /**
     * Where a machine is built or rebuilt, and the method that does it.
     *
     * Two files. {@see self::the_pinned_call_sites_are_every_call_site_in_the_application()}
     * asserts there is no third, so this is a closed list rather than a
     * hand-written one that can silently fall behind.
     *
     * @var array<string, array{method: string, request: string}>
     */
    private const array CLOUD_INIT_CALL_SITES = [
        'Modules/Vps/Application/Handlers/CreateVpsHandler.php' => ['method' => 'createVirtualMachine', 'request' => 'CreateVmRequest'],
        'Modules/Vps/Application/Handlers/ReinstallVpsHandler.php' => ['method' => 'reinstallVm', 'request' => 'ReinstallVmRequest'],
    ];

    /**
     * The production code that may bring a ComputeStorage row into existence,
     * and why each is allowed to.
     *
     * @var array<string, string>
     */
    private const array STORAGE_CREATORS = [
        'Modules/Compute/Application/Actions/SyncClusterInventory.php' => 'the inventory sync: what the cluster reported, read with Datastore.Audit',
        'Modules/Infrastructure/Application/Reference/LoadReferenceTopologyForSimulation.php' => 'the simulation loader: refuses production and stamps its rows development',
    ];

    protected function tearDown(): void
    {
        putenv(self::VARIABLE);

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // 1. Nothing VPS requires is Unknown by construction
    // -----------------------------------------------------------------------

    #[Test]
    public function every_compute_capability_vps_requires_is_settled_by_the_privilege_map(): void
    {
        $unsettled = array_values(array_filter(
            $this->vpsComputeCapabilities(),
            fn (string $capability): bool => $capability !== 'task_polling' && ! array_key_exists($capability, $this->privilegeMap()),
        ));

        $this->assertSame([], $unsettled, sprintf(
            "VPS requires these of compute and the Proxmox tester has no privilege that settles them, so a real cluster records them Unknown for ever and only the simulator can satisfy VPS:\n  %s",
            implode("\n  ", $unsettled),
        ));
    }

    #[Test]
    public function the_map_answers_only_questions_the_category_asks(): void
    {
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($this->privilegeMap()), ProviderCategory::Compute->capabilities())),
        );

        foreach ($this->privilegeMap() as $capability => $privileges) {
            $this->assertNotSame([], $privileges, sprintf('%s maps to no privilege, which would make it Supported on any token.', $capability));
        }
    }

    #[Test]
    public function every_privilege_the_map_asks_for_is_one_the_provisioning_role_grants(): void
    {
        $role = $this->provisioningRole();
        $asked = array_values(array_unique(array_merge(...array_values($this->privilegeMap()))));

        $this->assertSame([], array_values(array_diff($asked, $role)), sprintf(
            'The tester asks for privileges the role in %s does not grant, so a cluster this repository built itself would be told it cannot carry VPS.',
            basename(self::ROLE_FILE),
        ));

        // task_polling is decided outside the map, on Sys.Audit.
        $this->assertContains('Sys.Audit', $role);
    }

    #[Test]
    public function the_role_is_parsed_rather_than_assumed(): void
    {
        // An empty parse would make the subset assertion above vacuous.
        $role = $this->provisioningRole();

        $this->assertGreaterThanOrEqual(10, count($role));
        $this->assertContains('VM.Allocate', $role);
        $this->assertContains('Datastore.Audit', $role);
    }

    // -----------------------------------------------------------------------
    // 2. End to end, through the real tester
    // -----------------------------------------------------------------------

    #[Test]
    public function a_token_holding_the_provisioning_role_carries_vps_compute_to_ready_for_production(): void
    {
        $provider = $this->provenProxmox($this->provisioningRole());

        $recorded = $this->recorded($provider);

        foreach ($this->vpsComputeCapabilities() as $capability) {
            $this->assertSame(CapabilityState::Supported, $recorded[$capability] ?? null, sprintf(
                'The real tester, given the provisioning role, did not record %s as Supported.',
                $capability,
            ));
        }

        $compute = $this->computeVerdict($this->assess());

        $this->assertSame(ProductReadinessState::ReadyForProduction, $compute->satisfiedUpTo, sprintf(
            "A real Proxmox cluster whose token holds this repository's own provisioning role cannot carry VPS: %s",
            (string) $compute->detail,
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mappedPrivileges(): iterable
    {
        $constant = new ReflectionClassConstant(ProxmoxConnectionTester::class, 'PRIVILEGES');

        /** @var array<string, list<string>> $map */
        $map = $constant->getValue();

        foreach (array_unique(array_merge(...array_values($map))) as $privilege) {
            yield $privilege => [$privilege];
        }
    }

    #[Test]
    #[DataProvider('mappedPrivileges')]
    public function a_token_short_of_any_mapped_privilege_cannot_carry_vps(string $privilege): void
    {
        $held = array_values(array_diff($this->provisioningRole(), [$privilege]));

        $provider = $this->provenProxmox($held);

        $recorded = $this->recorded($provider);

        $lost = array_keys(array_filter(
            $this->privilegeMap(),
            static fn (array $privileges): bool => in_array($privilege, $privileges, strict: true),
        ));

        foreach ($lost as $capability) {
            $this->assertSame(CapabilityState::Unsupported, $recorded[$capability] ?? null, sprintf(
                '%s requires %s and was recorded as something other than Unsupported on a token without it.',
                $capability,
                $privilege,
            ));
        }

        $this->assertSame(
            ProductReadinessState::NotReady,
            $this->computeVerdict($this->assess())->satisfiedUpTo,
            sprintf('A token without %s was judged able to carry VPS.', $privilege),
        );
    }

    #[Test]
    public function a_token_short_of_datastore_audit_is_not_ready_because_it_cannot_see_a_storage_pool(): void
    {
        /*
         * The gap an earlier round left open on the ground that closing it
         * "would violate EveryDeclaredCapabilityHasAConsumerTest". That test
         * refuses a capability no product requirement names; VPS names this
         * one, so it is satisfied.
         */
        $provider = $this->provenProxmox(array_values(array_diff($this->provisioningRole(), ['Datastore.Audit'])));

        $this->assertSame(CapabilityState::Unsupported, $this->recorded($provider)['inventory_sync'] ?? null);

        $compute = $this->computeVerdict($this->assess());

        $this->assertSame(ProductReadinessState::NotReady, $compute->satisfiedUpTo);
        $this->assertStringContainsString('inventory_sync', (string) $compute->detail);
    }

    #[Test]
    public function the_controlled_hypervisor_with_everything_supported_reaches_only_ready_for_test(): void
    {
        $capabilities = [];

        foreach (ProviderCategory::Compute->capabilities() as $capability) {
            $capabilities[$capability] = CapabilityState::Supported;
        }

        $verdict = (new ProductReadinessEvaluator)->evaluate(
            Product::Vps,
            (new ProductRequirements)->for(Product::Vps),
            [new ProviderFacts(
                id: 'controlled-compute',
                name: 'controlled-compute',
                category: ProviderCategory::Compute,
                driver: ControlledDriver::Compute->value,
                controlled: true,
                environment: DeploymentEnvironment::Production,
                state: ProviderState::Enabled,
                readiness: ReadinessState::ReadyForProduction,
                blocker: null,
                capabilities: $capabilities,
            )],
            [],
        );

        $this->assertSame(ProductReadinessState::ReadyForTest, $this->computeVerdict($verdict)->satisfiedUpTo);
    }

    // -----------------------------------------------------------------------
    // 3. inventory_sync, in all four places
    // -----------------------------------------------------------------------

    #[Test]
    public function inventory_sync_is_asked_catalogued_required_and_mapped(): void
    {
        $this->assertContains('inventory_sync', ProviderCategory::Compute->capabilities(), 'The compute category no longer asks about inventory_sync.');

        $proxmox = (new ProviderCatalogue)->find('proxmox');
        $this->assertNotNull($proxmox);
        $this->assertContains('inventory_sync', $proxmox->capabilities, 'The Proxmox catalogue entry no longer offers inventory_sync.');

        $this->assertContains('inventory_sync', $this->vpsComputeCapabilities(), 'VPS no longer requires inventory_sync, so a token that cannot see a storage pool is ready again.');

        $this->assertSame(['Sys.Audit', 'Datastore.Audit'], $this->privilegeMap()['inventory_sync'] ?? null);
    }

    #[Test]
    public function only_vps_requires_inventory_sync_directly_and_gpu_compute_inherits_it(): void
    {
        /*
         * GPU compute is prepared software with no handler of its own, and it
         * depends on VPS — so it inherits VPS's readiness, inventory_sync
         * included, without naming it.
         */
        $requirements = new ProductRequirements;

        $naming = [];

        foreach (Product::cases() as $product) {
            foreach ($requirements->own($product) as $requirement) {
                if (in_array('inventory_sync', [...$requirement->capabilities, ...$requirement->optional], strict: true)) {
                    $naming[] = $product->value;
                }
            }
        }

        $this->assertSame([Product::Vps->value], $naming);
        $this->assertContains(Product::Vps, Product::GpuCompute->dependsOn());
    }

    // -----------------------------------------------------------------------
    // 4. The premise behind inventory_sync: who creates a storage row
    // -----------------------------------------------------------------------

    #[Test]
    public function only_the_inventory_sync_and_the_simulation_loader_create_storage_rows(): void
    {
        /*
         * WHAT THIS CHECK COUNTS. Comments are removed by the tokenizer and
         * string literals are single tokens, so neither a comment nor a
         * message quoting a creation can match or hide one. A file creates a
         * ComputeStorage row when, in its code, any of these appears:
         *
         *   ComputeStorage::<creator>(            static creator
         *   ComputeStorage::query()->…-><creator>( a query-built creator
         *   ->storages()->…-><creator>(           a relation, its names derived
         *                                         from the hasMany/hasOne calls
         *                                         in src/ rather than listed
         *   new ComputeStorage                    an instance, saved or not
         *   DB::table('compute_storages')         the table, bypassing the model
         *
         * ReserveNodeCapacity and ReleaseNodeCapacity write storage rows too,
         * by id: they update a row that exists and cannot bring one into
         * existence, so they are not creators.
         *
         * WHAT IT DOES NOT COUNT: a creator reached through a variable holding
         * the class name or a relation name assembled at run time.
         */
        $relations = $this->storageRelations();

        $this->assertNotSame([], $relations, 'No relation to ComputeStorage was derived, so the relation half of this scan proves nothing.');

        $creators = [];

        foreach ($this->sourceFiles() as $relative => $path) {
            if ($this->createsStorage($this->tokens($path), $relations)) {
                $creators[] = $relative;
            }
        }

        sort($creators);
        $expected = array_keys(self::STORAGE_CREATORS);
        sort($expected);

        $this->assertSame($expected, $creators, sprintf(
            "The set of code that can create a storage row has changed. inventory_sync is required of VPS because only the sync creates one in production; a new creator has to be justified here:\n  %s",
            implode("\n  ", $creators),
        ));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function writerShapes(): iterable
    {
        yield 'static create' => ['ComputeStorage::create([]);', true];
        yield 'query create' => ['ComputeStorage::query()->create([]);', true];
        yield 'query where firstOrCreate' => ["ComputeStorage::query()->where('a', 1)->firstOrCreate([]);", true];
        yield 'relation create' => ['$node->storages()->create([]);', true];
        yield 'relation firstOrCreate across a line' => ["\$cluster->storages()\n    ->firstOrCreate([]);", true];
        yield 'new and save' => ['(new ComputeStorage([]))->save();', true];
        yield 'table insert' => ["DB::table('compute_storages')->insert([]);", true];
        yield 'a comment quoting a creation' => ['// ComputeStorage::create([]) is not called here', false];
        yield 'a string quoting a creation' => ["throw new \\RuntimeException('Do not call ComputeStorage::create([]) here');", false];
        yield 'an update by id' => ['ComputeStorage::query()->lockForUpdate()->findOrFail($id)->save();', false];
        yield 'a read' => ["ComputeStorage::query()->where('a', 1)->get();", false];
    }

    #[Test]
    #[DataProvider('writerShapes')]
    public function the_writer_scan_recognises_each_shape_it_claims(string $code, bool $creates): void
    {
        $tokens = $this->tokensOf("<?php\n".$code."\n");

        $this->assertSame($creates, $this->createsStorage($tokens, ['storages']));
    }

    // -----------------------------------------------------------------------
    // 5. The premise behind VM.Config.Cloudinit: every build carries cloud-init
    // -----------------------------------------------------------------------

    #[Test]
    public function every_call_that_builds_or_rebuilds_a_machine_hands_the_adapter_a_cloud_init_config(): void
    {
        foreach (self::CLOUD_INIT_CALL_SITES as $relative => $site) {
            $tokens = $this->tokens(self::SRC.'/'.$relative);

            $problems = $this->cloudInitProblems($tokens, $site['method'], $site['request']);

            $this->assertSame([], $problems['missing'], sprintf(
                '%s calls %s without handing it %s(cloudInit: new CloudInitConfig(...)) at line(s) %s. '
                .'The privilege map asks VM.Config.Cloudinit of that capability because every such call carries cloud-init.',
                $relative,
                $site['method'],
                $site['request'],
                implode(', ', $problems['missing']),
            ));

            // The backstop: a call site written whole exists. Without it, a
            // file that reached the method only through a name assembled at
            // run time would pass by having nothing to check.
            $this->assertGreaterThan(0, $problems['found'], sprintf('%s no longer calls %s by name.', $relative, $site['method']));

            $this->assertSame([], $problems['dynamic'], sprintf(
                '%s dispatches a method through a variable or a callable at line(s) %s, which this pin cannot read.',
                $relative,
                implode(', ', $problems['dynamic']),
            ));
        }
    }

    #[Test]
    public function the_pinned_methods_are_every_method_that_takes_a_build_request(): void
    {
        $methods = [];

        foreach ((new ReflectionClass(ComputeProvider::class))->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && in_array($type->getName(), [CreateVmRequest::class, ReinstallVmRequest::class], strict: true)) {
                    $methods[] = $method->getName();
                }
            }
        }

        sort($methods);
        $pinned = array_column(self::CLOUD_INIT_CALL_SITES, 'method');
        sort($pinned);

        $this->assertSame($pinned, $methods);
    }

    #[Test]
    public function the_pinned_call_sites_are_every_call_site_in_the_application(): void
    {
        $methods = array_column(self::CLOUD_INIT_CALL_SITES, 'method');
        $callers = [];

        foreach ($this->sourceFiles() as $relative => $path) {
            $tokens = $this->tokens($path);

            foreach ($tokens as $i => $token) {
                if ($token[0] === T_STRING && in_array($token[1], $methods, strict: true) && $this->isCall($tokens, $i)) {
                    $callers[$relative] = true;
                }
            }
        }

        $callers = array_keys($callers);
        sort($callers);
        $pinned = array_keys(self::CLOUD_INIT_CALL_SITES);
        sort($pinned);

        $this->assertSame($pinned, $callers, 'A machine is built or rebuilt from a file the cloud-init pin does not read.');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function cloudInitShapes(): iterable
    {
        $good = "\$provider->createVirtualMachine(new CreateVmRequest(\n    nodeName: 'a',\n    cloudInit: new CloudInitConfig(sshKeys: []),\n));";

        yield 'the handler shape' => [$good, true];
        yield 'cloud-init removed' => ["\$provider->createVirtualMachine(new CreateVmRequest(\n    nodeName: 'a',\n));", false];
        yield 'cloud-init removed, a comment quoting it' => ["\$provider->createVirtualMachine(new CreateVmRequest(\n    nodeName: 'a',\n    // cloudInit: new CloudInitConfig(sshKeys: []),\n));", false];
        yield 'cloud-init moved into a string' => ["\$provider->createVirtualMachine(new CreateVmRequest(\n    nodeName: 'a',\n    description: 'cloudInit: new CloudInitConfig( ... ) dropped for now',\n));", false];
        yield 'cloud-init handed to a nested call' => ["\$provider->createVirtualMachine(new CreateVmRequest(\n    nodeName: \$this->name(cloudInit: new CloudInitConfig(sshKeys: [])),\n));", false];
        yield 'cloud-init null' => ["\$provider->createVirtualMachine(new CreateVmRequest(\n    nodeName: 'a',\n    cloudInit: null,\n));", false];
        yield 'a second, conditional call without it' => [$good."\nif (\$x) {\n    \$provider->createVirtualMachine(new CreateVmRequest(nodeName: 'b'));\n}", false];
        yield 'a method through a variable across a line break' => [$good."\n\$provider->\n    \$call(new CreateVmRequest(nodeName: 'b'));", false];
        yield 'a callable array' => [$good."\ncall_user_func([\$provider, 'createVirtualMachine'], new CreateVmRequest(nodeName: 'b'));", false];
    }

    #[Test]
    #[DataProvider('cloudInitShapes')]
    public function the_cloud_init_pin_reads_arguments_and_not_text(string $code, bool $passes): void
    {
        $problems = $this->cloudInitProblems($this->tokensOf("<?php\n".$code."\n"), 'createVirtualMachine', 'CreateVmRequest');

        $this->assertSame($passes, $problems['missing'] === [] && $problems['dynamic'] === [] && $problems['found'] > 0);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @return array<string, list<string>>
     */
    private function privilegeMap(): array
    {
        /** @var array<string, list<string>> $map */
        $map = (new ReflectionClassConstant(ProxmoxConnectionTester::class, 'PRIVILEGES'))->getValue();

        return $map;
    }

    /**
     * @return list<string>
     */
    private function vpsComputeCapabilities(): array
    {
        foreach ((new ProductRequirements)->own(Product::Vps) as $requirement) {
            if ($requirement->category === ProviderCategory::Compute) {
                return $requirement->capabilities;
            }
        }

        $this->fail('VPS has no compute requirement.');
    }

    /**
     * The privileges this repository's own automation grants the control
     * plane's token, read from the file that grants them.
     *
     * @return list<string>
     */
    private function provisioningRole(): array
    {
        $this->assertFileExists(self::ROLE_FILE);

        $lines = file(self::ROLE_FILE, FILE_IGNORE_NEW_LINES) ?: [];
        $privileges = [];
        $inList = false;

        foreach ($lines as $line) {
            if (preg_match('/^proxmox_api_role_privileges:\s*$/', $line) === 1) {
                $inList = true;

                continue;
            }

            if (! $inList) {
                continue;
            }

            if (preg_match('/^\s+-\s+([A-Za-z.]+)\s*$/', $line, $match) === 1) {
                $privileges[] = $match[1];

                continue;
            }

            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            break;
        }

        $this->assertNotSame([], $privileges, 'The provisioning role parsed to nothing.');

        return $privileges;
    }

    /**
     * A Proxmox provider row, tested by the real tester over a faked wire,
     * then promoted by hand to what an operator's enablement would make it.
     *
     * The promotion is the one link this file asserts rather than derives: a
     * production row pointed at a `.test` endpoint is refused before it is
     * tested, so the row is tested as staging and then written as production,
     * Enabled and ReadyForProduction. Everything the product verdict is judged
     * on below that — the capability rows — is the real tester's answer.
     *
     * @param  list<string>  $privileges
     */
    private function provenProxmox(array $privileges): ProviderInstance
    {
        Http::fake([
            '*/api2/json/version*' => Http::response(['data' => ['version' => '8.2.4', 'release' => '8.2', 'repoid' => 'faa83925c9f0e5a3']]),
            '*/api2/json/nodes*' => Http::response(['data' => [['node' => 'pve-1', 'status' => 'online']]]),
            '*/api2/json/access/permissions*' => Http::response(['data' => [
                '/' => array_fill_keys($privileges, 1),
            ]]),
        ]);

        putenv(self::VARIABLE.'=lynomia@pve!control-plane=00000000-0000-0000-0000-000000000000');

        $credential = CredentialReference::query()->create([
            'name' => 'f13-proxmox',
            'purpose' => 'A structurally valid Proxmox token that is the secret of nothing.',
            'environment' => DeploymentEnvironment::Staging->value,
            'backend' => 'controller_environment',
            'backend_reference' => self::VARIABLE,
            'state' => 'configured',
        ]);

        $provider = ProviderInstance::query()->create([
            'name' => 'pve-f13',
            'category' => ProviderCategory::Compute->value,
            'driver' => 'proxmox',
            'environment' => DeploymentEnvironment::Staging->value,
            'state' => ProviderState::Draft->value,
            'endpoint' => 'https://pve.example.test:8006',
            'credential_reference_id' => $credential->getKey(),
        ]);

        $record = app(TestConnection::class)->forProvider($provider);

        $this->assertTrue($record->result->usable(), 'The real tester did not reach a usable state, so nothing below is about capabilities.');

        $provider->forceFill([
            'environment' => DeploymentEnvironment::Production,
            'state' => ProviderState::Enabled,
            'readiness' => ReadinessState::ReadyForProduction,
            'blocker' => null,
        ])->save();

        return $provider->refresh();
    }

    /**
     * @return array<string, CapabilityState>
     */
    private function recorded(ProviderInstance $provider): array
    {
        $recorded = [];

        foreach (ProviderCapability::query()->where('provider_instance_id', $provider->getKey())->get() as $row) {
            $recorded[$row->capability] = $row->state;
        }

        return $recorded;
    }

    private function assess(): ProductVerdict
    {
        return app(AssessProduct::class)->verdictFor(Product::Vps);
    }

    private function computeVerdict(ProductVerdict $verdict): RequirementVerdict
    {
        foreach ($verdict->requirements as $requirement) {
            if ($requirement->requirement->category === ProviderCategory::Compute) {
                return $requirement;
            }
        }

        $this->fail('VPS has no compute requirement verdict.');
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function sourceFiles(): array
    {
        $root = realpath(self::SRC);
        $this->assertIsString($root);

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return list<array{0: int|string, 1: string, 2: int}>
     */
    private function tokens(string $path): array
    {
        return $this->tokensOf((string) file_get_contents($path));
    }

    /**
     * The file's code, without whitespace or comments. Strings stay, each as
     * one token, so nothing inside a string can be read as code.
     *
     * @return list<array{0: int|string, 1: string, 2: int}>
     */
    private function tokensOf(string $source): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $line = $token[2];

                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], strict: true)) {
                    continue;
                }

                $tokens[] = [$token[0], $token[1], $token[2]];

                continue;
            }

            $tokens[] = [$token, $token, $line];
        }

        return $tokens;
    }

    /**
     * Relation methods that return ComputeStorage rows, derived from the
     * models rather than listed.
     *
     * @return list<string>
     */
    private function storageRelations(): array
    {
        $relations = [];

        foreach ($this->sourceFiles() as $path) {
            $tokens = $this->tokens($path);
            $method = null;

            foreach ($tokens as $i => $token) {
                if ($token[0] === T_FUNCTION && ($tokens[$i + 1][0] ?? null) === T_STRING) {
                    $method = $tokens[$i + 1][1];
                }

                if ($method !== null
                    && $token[0] === T_STRING
                    && in_array($token[1], ['hasMany', 'hasOne'], strict: true)
                    && ($tokens[$i + 1][1] ?? null) === '('
                    && $this->names($tokens[$i + 2] ?? null, 'ComputeStorage')
                    && ($tokens[$i + 3][0] ?? null) === T_DOUBLE_COLON
                ) {
                    $relations[$method] = true;
                }
            }
        }

        return array_keys($relations);
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  list<string>  $relations
     */
    private function createsStorage(array $tokens, array $relations): bool
    {
        $creators = ['create', 'createMany', 'createQuietly', 'forceCreate', 'forceCreateQuietly', 'firstOrCreate', 'updateOrCreate', 'createOrFirst', 'insert', 'insertOrIgnore', 'insertGetId', 'upsert', 'save', 'saveMany', 'saveQuietly'];
        $table = (new ComputeStorage)->getTable();
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // new ComputeStorage
            if ($token[0] === T_NEW && $this->names($tokens[$i + 1] ?? null, 'ComputeStorage')) {
                return true;
            }

            $chainStart = null;

            // ComputeStorage::…
            if ($this->names($token, 'ComputeStorage') && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON) {
                $chainStart = $i + 2;
            }

            // DB::table('compute_storages')…
            if ($token[0] === T_STRING && $token[1] === 'table'
                && ($tokens[$i + 1][1] ?? null) === '('
                && ($tokens[$i + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
                && trim($tokens[$i + 2][1], '\'"') === $table
            ) {
                $chainStart = $i + 1;
            }

            // ->storages()…
            if (in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], strict: true)
                && ($tokens[$i + 1][0] ?? null) === T_STRING
                && in_array($tokens[$i + 1][1], $relations, strict: true)
                && ($tokens[$i + 2][1] ?? null) === '('
            ) {
                $chainStart = $i + 2;
            }

            if ($chainStart === null) {
                continue;
            }

            foreach ($this->chainMethods($tokens, $chainStart) as $name) {
                if (in_array($name, ['find', 'findOrFail', 'findMany', 'lockForUpdate', 'whereKey'], strict: true)) {
                    // Reached by id: whatever follows updates a row that
                    // exists and cannot bring one into existence.
                    break;
                }

                if (in_array($name, $creators, strict: true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The method names called along a fluent chain starting at $i, skipping
     * each call's arguments.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @return list<string>
     */
    private function chainMethods(array $tokens, int $i): array
    {
        $names = [];
        $count = count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            if ($token[1] === '(') {
                $i = $this->closing($tokens, $i) + 1;

                continue;
            }

            if ($token[0] === T_STRING && ($tokens[$i + 1][1] ?? null) === '(') {
                $names[] = $token[1];
                $i++;

                continue;
            }

            if (in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], strict: true)) {
                $i++;

                continue;
            }

            if ($token[1] === ')' && $names !== []) {
                // `(new ComputeStorage(...))->save()` closes before its chain.
                $i++;

                continue;
            }

            break;
        }

        return $names;
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @return array{found: int, missing: list<int>, dynamic: list<int>}
     */
    private function cloudInitProblems(array $tokens, string $method, string $request): array
    {
        $found = 0;
        $missing = [];
        $dynamic = [];

        foreach ($tokens as $i => $token) {
            // A method called through a variable or an expression, however
            // it is broken across lines: whitespace is not a token here.
            if (in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], strict: true)
                && in_array($tokens[$i + 1][0] ?? null, [T_VARIABLE, '{'], strict: true)
            ) {
                $dynamic[] = $token[2];
            }

            if ($token[0] === T_STRING && in_array(strtolower($token[1]), ['call_user_func', 'call_user_func_array'], strict: true)) {
                $dynamic[] = $token[2];
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING && trim($token[1], '\'"') === $method) {
                $dynamic[] = $token[2];
            }

            if ($token[0] !== T_STRING || $token[1] !== $method || ! $this->isCall($tokens, $i)) {
                continue;
            }

            $found++;

            if (! $this->carriesCloudInit($tokens, $i + 1, $request)) {
                $missing[] = $token[2];
            }
        }

        return ['found' => $found, 'missing' => $missing, 'dynamic' => $dynamic];
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     */
    private function isCall(array $tokens, int $i): bool
    {
        return in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], strict: true)
            && ($tokens[$i + 1][1] ?? null) === '(';
    }

    /**
     * Does the call whose argument list opens at $open hand over
     * `new $request(... cloudInit: new CloudInitConfig ...)` — both at the
     * top level of their own argument lists?
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     */
    private function carriesCloudInit(array $tokens, int $open, string $request): bool
    {
        foreach ($this->arguments($tokens, $open) as $argument) {
            if (($argument[0][0] ?? null) !== T_NEW || ! $this->names($argument[1] ?? null, $request) || ($argument[2][1] ?? null) !== '(') {
                continue;
            }

            $offset = $this->offsetOf($tokens, $argument[2]);

            foreach ($this->arguments($tokens, $offset) as $inner) {
                if (($inner[0][1] ?? null) === 'cloudInit'
                    && ($inner[1][1] ?? null) === ':'
                    && ($inner[2][0] ?? null) === T_NEW
                    && $this->names($inner[3] ?? null, 'CloudInitConfig')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The top-level arguments of the list that opens at $open.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @return list<list<array{0: int|string, 1: string, 2: int, 3?: int}>>
     */
    private function arguments(array $tokens, int $open): array
    {
        $close = $this->closing($tokens, $open);
        $arguments = [];
        $current = [];
        $depth = 0;

        for ($i = $open + 1; $i < $close; $i++) {
            $text = $tokens[$i][1];

            if (in_array($text, ['(', '[', '{'], strict: true) || $tokens[$i][0] === T_CURLY_OPEN || $tokens[$i][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], strict: true)) {
                $depth--;
            }

            if ($depth === 0 && $text === ',') {
                $arguments[] = $current;
                $current = [];

                continue;
            }

            $current[] = [$tokens[$i][0], $tokens[$i][1], $tokens[$i][2], $i];
        }

        if ($current !== []) {
            $arguments[] = $current;
        }

        return $arguments;
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  array{0: int|string, 1: string, 2: int, 3?: int}  $token
     */
    private function offsetOf(array $tokens, array $token): int
    {
        return $token[3] ?? throw new \LogicException('An argument token carries no offset.');
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     */
    private function closing(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i][1] === '(') {
                $depth++;
            } elseif ($tokens[$i][1] === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    /**
     * Is this token the class name, however qualified?
     *
     * @param  array{0: int|string, 1: string, 2: int, 3?: int}|null  $token
     */
    private function names(?array $token, string $class): bool
    {
        if ($token === null || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], strict: true)) {
            return false;
        }

        $parts = explode('\\', $token[1]);

        return end($parts) === $class;
    }
}
