<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\ReinstallVmRequest;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\ProductReadiness\Application\Actions\AssessProduct;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProductVerdict;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProviderFacts;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\RequirementVerdict;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductReadinessEvaluator;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductRequirements;
use Lynomia\Modules\Providers\Application\Actions\EnableProvider;
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
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;
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
 * Unknown, so no real cluster could satisfy VPS's compute requirement — and
 * Proxmox is the only real compute driver the catalogue has. Only the
 * simulator's capability set satisfied it, and the simulator is not evidence:
 * it reaches ReadyForTest and no further, then as now. So VPS could reach
 * ReadyForProduction nowhere. It failed closed, which is why nobody was hurt
 * by it, and it meant the product the platform exists to sell could only ever
 * be exercised against its own fake.
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
 *  3. End to end, through the real tester, the real factory and the real
 *     enablement with only the wire faked, and nothing written by hand: that
 *     role carries VPS's compute requirement to ReadyForProduction, and the
 *     token short of any one mapped privilege does not. The converse is
 *     pinned too: the controlled hypervisor with every capability Supported
 *     reaches only ReadyForTest.
 *  4. `inventory_sync`. A token short of `Datastore.Audit` reads no storage
 *     pools, so the inventory sync records none, and the scheduler — which
 *     places only on recorded pools — places nothing. On Proxmox `templates`
 *     needs the same privilege, so such a token is refused either way;
 *     `inventory_sync` is the requirement that names why placement fails,
 *     and keeps the refusal if `templates` is ever settled some other way.
 *     It is a capability in four places (the category, the Proxmox
 *     catalogue entry, the VPS requirement and the privilege map) and this
 *     file breaks if the four stop agreeing; the architecture gate cannot
 *     see all four removed together.
 *  5. The two premises the map rests on, each checked at source:
 *     - `create` and `reinstall` ask for `VM.Config.Cloudinit` because every
 *       call that builds or rebuilds a machine hands the adapter a
 *       cloud-init config. The cloud-init pin reads every call to either
 *       build method in each handler — not only the handler's own — token by
 *       token, so a comment or a string that merely quotes `cloudInit:` does
 *       not satisfy it. The caller set reads every file of the application
 *       and holds that no other file names a build method whole, apart from
 *       one browser-suite seeder whose every call is checked to be made on
 *       the fake hypervisor. And the map is held to its half: both build
 *       capabilities still ask for `VM.Config.Cloudinit`.
 *     - `inventory_sync` is load-bearing because the inventory sync is the
 *       only production code that brings a ComputeStorage row into
 *       existence. The writer scan establishes that, and states exactly what
 *       it counts (below).
 *
 * ===========================================================================
 * WHAT THIS FILE DOES NOT REACH
 * ===========================================================================
 *
 *  - The contents of each privilege list are the tester's reading of what
 *    the adapter's requests need from the Proxmox API, and are not derived.
 *    This file pins that the role grants every one of them, that the build
 *    capabilities ask for `VM.Config.Cloudinit`, and `inventory_sync`'s list
 *    exactly; a privilege dropped from any other list is caught only if
 *    that drop happens to change a verdict, which in general it does not.
 *  - A privilege held only at one path counts as held; the tester flattens
 *    scope. Recorded on the ledger, moot for this repository's clusters, whose
 *    automation grants the role at `/`.
 *  - `suspend` and `unsuspend` map to `VM.PowerMgmt` alone while the adapter
 *    also writes `onboot` and `lock` (`VM.Config.Options`). No verdict moves,
 *    because both compute products require `create`, which requires
 *    `VM.Config.Options`; the tester's answer for those two capabilities is
 *    still wrong for such a token, and that is recorded rather than fixed.
 *  - The cloud-init pin and the caller set see a build method only when its
 *    name is written whole: called or taken as a callable in any case, or
 *    named in a string. A name assembled at run time is invisible to both;
 *    what stops that from removing cloud-init from the *last*
 *    machine-building call is the per-file backstop that each pinned file
 *    still calls its own method by name. So a hidden call site can add a
 *    machine without cloud-init; it cannot take cloud-init off the one the
 *    backstop sees.
 */
final class ARealProxmoxClusterCanCarryVpsTest extends TestCase
{
    use RefreshDatabase;

    private const string VARIABLE = 'LYNOMIA_TEST_F13_PROXMOX_TOKEN';

    private const string ROLE_FILE = __DIR__.'/../../../../../infrastructure/ansible/group_vars/proxmox.yml';

    /**
     * The control plane's own directory. Both scans below read every PHP file
     * under it except the tests, the dependencies and what the framework
     * writes at run time — so a console command, a migration or a seeder is
     * read as well as src/.
     */
    private const string ROOT = __DIR__.'/../../..';

    /** @var list<string> */
    private const array NOT_APPLICATION_CODE = ['tests/', 'vendor/', 'node_modules/', 'storage/', 'tools/', 'bootstrap/cache/'];

    /**
     * The adapter methods that build or rebuild a machine: the request each one
     * takes, and the capability whose privileges it spends.
     *
     * @var array<string, array{request: string, capability: string}>
     */
    private const array BUILD_METHODS = [
        'createVirtualMachine' => ['request' => 'CreateVmRequest', 'capability' => 'create'],
        'reinstallVm' => ['request' => 'ReinstallVmRequest', 'capability' => 'reinstall'],
    ];

    /**
     * Where a machine is built or rebuilt, and the method each file exists to
     * call. Every build method is checked in every file here, not only the
     * file's own; the file's own method is what the backstop requires to be
     * called by name.
     *
     * Two files. {@see self::the_pinned_call_sites_are_every_call_site_in_the_application()}
     * asserts that no other file names a build method whole — apart from
     * {@see self::FAKE_ONLY_CALLERS}, whose every call it checks is made on
     * the fake — so this is a closed list rather than a hand-written one that
     * can silently fall behind.
     *
     * @var array<string, string>
     */
    private const array CLOUD_INIT_CALL_SITES = [
        'src/Modules/Vps/Application/Handlers/CreateVpsHandler.php' => 'createVirtualMachine',
        'src/Modules/Vps/Application/Handlers/ReinstallVpsHandler.php' => 'reinstallVm',
    ];

    /**
     * Files that name a build method and are not pinned, and why each may.
     *
     * Every build call in them is made on the fake hypervisor, constructed by
     * class in the call itself, so no cluster is asked for a privilege and the
     * premise is not about them. The caller-set test checks each call's
     * receiver rather than trusting the reason.
     *
     * @var array<string, string>
     */
    private const array FAKE_ONLY_CALLERS = [
        'database/seeders/E2ESeeder.php' => 'the browser suite\'s fixture registers a machine with the fake hypervisor, and the seeder refuses production',
    ];

    /**
     * The production code that may bring a ComputeStorage row into existence,
     * and why each is allowed to.
     *
     * @var array<string, string>
     */
    private const array STORAGE_CREATORS = [
        'src/Modules/Compute/Application/Actions/SyncClusterInventory.php' => 'the inventory sync: what the cluster reported, read with Datastore.Audit',
        'src/Modules/Infrastructure/Application/Reference/LoadReferenceTopologyForSimulation.php' => 'the simulation loader: refuses production and stamps its rows development',
    ];

    /** @var list<string>|null */
    private ?array $creatorMethods = null;

    /** @var list<string>|null derived once per process: the files do not change under it */
    private static ?array $storageClasses = null;

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
         * WHAT THIS CHECK COUNTS. Every PHP file of the application is read
         * (src/, app/, database/, routes/, config/ and the rest — not tests/
         * or vendor/). Comments are removed by the tokenizer and string
         * literals are single tokens, so neither a comment nor a message
         * quoting a creation can match or hide one. A file creates a
         * ComputeStorage row when, in its code, any of these appears:
         *
         *   ComputeStorage::<creator>(             static creator
         *   ComputeStorage::query()->…-><creator>( a query-built creator,
         *                                          whatever comes before it
         *   ->storages()->…-><creator>(            a relation, called or read as
         *   ->storages->…-><creator>(              a property; its names derived
         *                                          from the hasMany, hasOne,
         *                                          morphMany, morphOne and
         *                                          belongsToMany declarations
         *                                          rather than listed
         *   ->table('compute_storages')->…-><creator>(
         *                                          the table, bypassing the
         *                                          model, aliased or not
         *   new ComputeStorage                     an instance, saved or not
         *   [ComputeStorage::class, '<creator>']   a callable array, on the
         *   [<a chain above>, '<creator>']         class (or its name in a
         *                                          string) or on a chain
         *   '…ComputeStorage::<creator>'           a callable string
         *   'INSERT INTO compute_storages …'       SQL in a string: INSERT or
         *                                          MERGE INTO, or COPY … FROM
         *
         * ComputeStorage stands for every name the model goes by, derived
         * rather than listed: the class however qualified and in any case, a
         * class the application declares extending it or naming it as a
         * factory's `$model` (to a fixed point), an alias a file imports any
         * of those under with `use … as`, and `self`, `static` and `parent`
         * inside a file that declares one of them. `new class extends
         * ComputeStorage` counts as an instance.
         *
         * <creator> is every method the framework's builders, relations,
         * model and factories offer that makes a row, or an instance that
         * saving makes into one — create…, insert…, upsert, …OrCreate, …OrNew,
         * …OrInsert, save…, push…, make…, replicate…, newInstance — read off
         * those classes by reflection rather than listed, matched in any case
         * as PHP matches a method name, and read through a literal `->{'…'}(`
         * too.
         *
         * One chain is not a creator: a row found by its id (find, findOrFail,
         * findMany), filled or not, and saved or pushed. That writes the row
         * that was found. Anything else after the find — replicate,
         * newInstance, a create forwarded through the model — is a creator
         * again. ReserveNodeCapacity and ReleaseNodeCapacity lock a row by id
         * and write it through a variable, so nothing in their chains creates
         * one; the two verbatim lines are among the shapes below.
         *
         * WHAT IT DOES NOT COUNT: anything reached through a variable — one
         * holding the class name, a query, a relation, an instance or a method
         * name, `$this` included (`$query->create(…)`,
         * `$storage->replicate()->save()`, `->$method(…)`); the class name used
         * as a value other than in a callable (`app(ComputeStorage::class)`,
         * `class_alias(…)`); any name or SQL assembled at run time; and code
         * in a Blade template, which the tokenizer reads as text. Each of
         * those needs to know what a value will be, which a token scan cannot.
         */
        $relations = $this->storageRelations();

        $this->assertNotSame([], $relations, 'No relation to ComputeStorage was derived, so the relation half of this scan proves nothing.');
        $this->assertContains('computestoragefactory', $this->storageClasses(), 'The model\'s own factory was not derived, so the derivation of the names the model goes by proves nothing.');

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
        yield 'locked, then firstOrCreate' => ["ComputeStorage::query()->lockForUpdate()->firstOrCreate(['name' => 'x']);", true];
        yield 'locked, then updateOrCreate' => ["ComputeStorage::query()->lockForUpdate()->updateOrCreate(['name' => 'x'], []);", true];
        yield 'by key, then firstOrCreate' => ["ComputeStorage::query()->whereKey(\$id)->firstOrCreate(['name' => 'x']);", true];
        yield 'found by id, replicated and saved' => ['ComputeStorage::query()->findOrFail($id)->replicate()->save();', true];
        yield 'found by id, a new instance saved' => ['ComputeStorage::query()->findOrFail($id)->newInstance([])->save();', true];
        yield 'found by id, then a create forwarded through the model' => ['ComputeStorage::query()->find($id)->create([]);', true];
        yield 'static make' => ["ComputeStorage::make(['name' => 'x']);", true];
        yield 'relation make' => ["\$node->storages()->make(['name' => 'x']);", true];
        yield 'firstOrNew' => ["ComputeStorage::query()->firstOrNew(['name' => 'x'])->saveOrFail();", true];
        yield 'incrementOrCreate' => ["ComputeStorage::query()->incrementOrCreate(['name' => 'x']);", true];
        yield 'relation forceCreateMany' => ['$cluster->storages()->forceCreateMany([]);', true];
        yield 'table updateOrInsert' => ["DB::table('compute_storages')->updateOrInsert(['name' => 'x']);", true];
        yield 'table insertUsing' => ["DB::table('compute_storages')->insertUsing(['name'], \$query);", true];
        yield 'a creator in another case' => ["ComputeStorage::Create(['name' => 'x']);", true];
        yield 'a relation in another case' => ['$node->Storages()->create([]);', true];
        yield 'a creator named in a literal' => ["ComputeStorage::query()->{'create'}([]);", true];
        yield 'SQL that inserts into the table' => ["DB::insert('insert into compute_storages (name) values (?)', ['x']);", true];
        yield 'SQL that inserts into the table, quoted, in a heredoc' => ["DB::statement(<<<'SQL'\n    INSERT INTO \"public\".\"compute_storages\" (name) SELECT name FROM other\n    SQL);", true];
        yield 'SQL that copies into the table' => ["DB::statement('COPY compute_storages (name) FROM STDIN');", true];
        yield 'a callable array on the class' => ["call_user_func([ComputeStorage::class, 'create'], []);", true];
        yield 'a callable array on the class, named in a string' => ["call_user_func(['Lynomia\\Modules\\Compute\\Infrastructure\\Models\\ComputeStorage', 'forceCreate'], []);", true];
        yield 'a callable array on a relation' => ["call_user_func([\$node->storages(), 'create'], []);", true];
        yield 'a callable array on a query' => ["\$make = [ComputeStorage::query(), 'firstOrCreate'];", true];
        yield 'a callable string' => ["call_user_func('Lynomia\\Modules\\Compute\\Infrastructure\\Models\\ComputeStorage::create', []);", true];
        yield 'a relation read as a property' => ['$node->storages->first()->replicate()->save();', true];
        yield 'the table under an alias' => ["DB::table('compute_storages as s')->insert([]);", true];
        yield 'the model under an imported alias' => ["use Lynomia\\Modules\\Compute\\Infrastructure\\Models\\ComputeStorage as Pool;\nPool::create([]);", true];
        yield 'the model under an alias in a group import' => ["use Lynomia\\Modules\\Compute\\Infrastructure\\Models\\{ComputeNode, ComputeStorage as Pool};\nPool::query()->firstOrCreate([]);", true];
        yield 'the model, relatively qualified' => ['namespace\\ComputeStorage::create([]);', true];
        yield 'a subclass of the model' => ["class Pool extends ComputeStorage {}\nPool::create([]);", true];
        yield 'a subclass of a subclass' => ["class Pool extends ComputeStorage {}\nclass FastPool extends Pool {}\nFastPool::query()->create([]);", true];
        yield 'an anonymous subclass' => ['(new class([]) extends ComputeStorage {})->save();', true];
        yield 'static, inside the model' => ["class ComputeStorage extends Model {\n    public static function adopt(): static { return static::create([]); }\n}", true];
        yield 'new self, inside a subclass' => ["class Pool extends ComputeStorage {\n    public static function adopt(): self { return new self; }\n}", true];
        yield 'a factory of the model' => ["class PoolFactory extends Factory { protected \$model = ComputeStorage::class; }\nPoolFactory::new()->count(2)->create();", true];
        yield 'the model\'s own factory' => ['ComputeStorage::factory()->createOne();', true];
        yield 'an update by id' => ['ComputeStorage::query()->lockForUpdate()->findOrFail($id)->save();', false];
        yield 'an update by id, filled first' => ['ComputeStorage::query()->findOrFail($id)->forceFill([])->saveQuietly();', false];
        yield 'the reservation lock, verbatim' => ['$storage = ComputeStorage::query()->lockForUpdate()->findOrFail($storageId);', false];
        yield 'the release lock, verbatim' => ['$storage = ComputeStorage::query()->lockForUpdate()->find($storageId);', false];
        yield 'a read' => ["ComputeStorage::query()->where('a', 1)->get();", false];
        yield 'SQL that reads the table' => ["DB::select('select * from compute_storages where id = ?', [1]);", false];
        yield 'SQL that copies the table out' => ["DB::statement('COPY compute_storages TO STDOUT');", false];
        yield 'the table name alone' => ["return 'compute_storages';", false];
        yield 'the class as a relation target' => ["return \$this->hasMany(ComputeStorage::class, 'node_id');", false];
        yield 'the found rows of a relation, read' => ['$node->storages->first()->name;', false];
        yield 'static, inside another class' => ["class Ledger {\n    public static function open(): static { return static::create([]); }\n}", false];
        yield 'a subclass of another model' => ["class Pool extends ComputeNode {}\nPool::create([]);", false];
        yield 'the model, aliased and only read' => ["use Lynomia\\Modules\\Compute\\Infrastructure\\Models\\ComputeStorage as Pool;\nPool::query()->where('a', 1)->get();", false];
    }

    #[Test]
    #[DataProvider('writerShapes')]
    public function the_writer_scan_recognises_each_shape_it_claims(string $code, bool $creates): void
    {
        $tokens = $this->tokensOf("<?php\n".$code."\n");

        $this->assertSame($creates, $this->createsStorage($tokens, ['storages']));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function relationShapes(): iterable
    {
        yield 'hasMany' => ["public function storages(): HasMany { return \$this->hasMany(ComputeStorage::class, 'node_id'); }", ['storages']];
        yield 'hasOne, fully qualified' => ['public function pool(): HasOne { return $this->hasOne(\\Lynomia\\Modules\\Compute\\Infrastructure\\Models\\ComputeStorage::class); }', ['pool']];
        yield 'a named argument' => ['public function pools(): HasMany { return $this->hasMany(related: ComputeStorage::class); }', ['pools']];
        yield 'the class named in a string' => ["public function pools(): HasMany { return \$this->hasMany('Lynomia\\Modules\\Compute\\Infrastructure\\Models\\ComputeStorage'); }", ['pools']];
        yield 'morphMany' => ["public function pools(): MorphMany { return \$this->morphMany(ComputeStorage::class, 'owner'); }", ['pools']];
        yield 'belongsToMany' => ['public function pools(): BelongsToMany { return $this->belongsToMany(ComputeStorage::class); }', ['pools']];
        yield 'another case' => ['public function Pools(): HasMany { return $this->HasMany(computestorage::class); }', ['pools']];
        yield 'belongsTo, which cannot create the row it points at' => ['public function storage(): BelongsTo { return $this->belongsTo(ComputeStorage::class); }', []];
        yield 'another model' => ['public function nodes(): HasMany { return $this->hasMany(ComputeNode::class); }', []];
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('relationShapes')]
    public function the_relation_names_are_derived_from_every_declaration_that_can_create(string $code, array $expected): void
    {
        $this->assertSame($expected, $this->relationsIn($this->tokensOf("<?php\n".$code."\n")));
    }

    // -----------------------------------------------------------------------
    // 5. The premise behind VM.Config.Cloudinit: every build carries cloud-init
    // -----------------------------------------------------------------------

    #[Test]
    public function every_call_that_builds_or_rebuilds_a_machine_hands_the_adapter_a_cloud_init_config(): void
    {
        foreach (self::CLOUD_INIT_CALL_SITES as $relative => $own) {
            $problems = $this->cloudInitFileProblems($this->tokens(self::ROOT.'/'.$relative), $own);

            $this->assertSame([], $problems['missing'], sprintf(
                "%s builds or rebuilds a machine without handing the adapter a request carrying cloudInit: new CloudInitConfig(...):\n  %s\n"
                .'The privilege map asks VM.Config.Cloudinit of that capability because every such call carries cloud-init.',
                $relative,
                implode("\n  ", $problems['missing']),
            ));

            // The backstop: the file's own method is called by name. Without
            // it, a file that reached the method only through a name assembled
            // at run time would pass by having nothing to check.
            $this->assertGreaterThan(0, $problems['found'], sprintf('%s no longer calls %s by name.', $relative, $own));

            $this->assertSame([], $problems['dynamic'], sprintf(
                '%s dispatches a method through a variable or a callable at line(s) %s, which this pin cannot read.',
                $relative,
                implode(', ', $problems['dynamic']),
            ));
        }
    }

    #[Test]
    public function the_capabilities_that_build_a_machine_ask_for_the_cloud_init_privilege(): void
    {
        /*
         * The other half of the premise the pin above holds: the pin is why
         * these two capabilities ask for VM.Config.Cloudinit, and without this
         * the privilege could leave the map while the pin stayed green — a
         * token the cluster refuses on the first order would read Supported.
         */
        foreach (self::BUILD_METHODS as $method => $build) {
            $this->assertContains('VM.Config.Cloudinit', $this->privilegeMap()[$build['capability']] ?? [], sprintf(
                '%s hands the adapter cloud-init, so %s must ask for VM.Config.Cloudinit.',
                $method,
                $build['capability'],
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
                    $methods[$method->getName()] = (new ReflectionClass($type->getName()))->getShortName();
                }
            }
        }

        ksort($methods);
        $pinned = array_map(static fn (array $build): string => $build['request'], self::BUILD_METHODS);
        ksort($pinned);

        $this->assertSame($pinned, $methods);

        // And each one is some pinned file's own method, so the backstop
        // covers every build method.
        $owned = array_values(array_unique(self::CLOUD_INIT_CALL_SITES));
        sort($owned);

        $this->assertSame(array_keys($pinned), $owned);
    }

    #[Test]
    public function the_pinned_call_sites_are_every_call_site_in_the_application(): void
    {
        $callers = [];
        $fakeOnly = [];

        foreach ($this->sourceFiles() as $relative => $path) {
            $tokens = $this->tokens($path);

            if (! $this->reachesABuildMethod($tokens)) {
                continue;
            }

            if (array_key_exists($relative, self::FAKE_ONLY_CALLERS)) {
                $this->assertTrue($this->callsOnlyTheFake($tokens), sprintf(
                    '%s may name a build method only as (new FakeComputeProvider)->…(, and now reaches one some other way.',
                    $relative,
                ));

                $fakeOnly[] = $relative;

                continue;
            }

            $callers[] = $relative;
        }

        sort($callers);
        $pinned = array_keys(self::CLOUD_INIT_CALL_SITES);
        sort($pinned);

        $this->assertSame($pinned, $callers, 'A machine is built or rebuilt from a file the cloud-init pin does not read.');

        // An exemption that no longer applies is removed rather than kept.
        $this->assertSame(array_keys(self::FAKE_ONLY_CALLERS), $fakeOnly);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function fakeOnlyShapes(): iterable
    {
        $import = "use Lynomia\\Modules\\Compute\\Infrastructure\\Providers\\FakeComputeProvider;\n";

        yield 'the seeder shape' => [$import.'(new FakeComputeProvider)->createVirtualMachine($request);', true];
        yield 'constructed with parentheses' => [$import.'(new FakeComputeProvider())->reinstallVm(\'a\', \'b\', $request);', true];
        yield 'fully qualified, no import' => ['(new \\Lynomia\\Modules\\Compute\\Infrastructure\\Providers\\FakeComputeProvider)->createVirtualMachine($request);', true];
        yield 'not imported, so another class' => ['(new FakeComputeProvider)->createVirtualMachine($request);', false];
        yield 'another class imported under the name' => ["use Lynomia\\Modules\\Compute\\Infrastructure\\Providers\\ProxmoxComputeProvider as FakeComputeProvider;\n(new FakeComputeProvider)->createVirtualMachine(\$request);", false];
        yield 'another provider' => [$import.'(new ProxmoxComputeProvider)->createVirtualMachine($request);', false];
        yield 'through a variable' => [$import.'$fake = new FakeComputeProvider; $fake->createVirtualMachine($request);', false];
        yield 'a callable array' => [$import."call_user_func([new FakeComputeProvider, 'createVirtualMachine'], \$request);", false];
    }

    #[Test]
    #[DataProvider('fakeOnlyShapes')]
    public function a_fake_only_caller_is_read_by_its_receiver(string $code, bool $fakeOnly): void
    {
        $this->assertSame($fakeOnly, $this->callsOnlyTheFake($this->tokensOf("<?php\n".$code."\n")));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function callerShapes(): iterable
    {
        yield 'a call' => ['$provider->createVirtualMachine($request);', true];
        yield 'a call across a line break' => ["\$provider\n    ->reinstallVm('a', 'b', \$request);", true];
        yield 'a first-class callable' => ['$build = $provider->createVirtualMachine(...);', true];
        yield 'a call in another case' => ["\$provider->ReinstallVM('a', 'b', \$request);", true];
        yield 'a callable array' => ["call_user_func([\$provider, 'createVirtualMachine'], \$request);", true];
        yield 'a callable array, kept for later' => ["\$build = [\$provider, 'reinstallVm'];", true];
        yield 'a method named in a string' => ["\$provider->{'createVirtualMachine'}(\$request);", true];
        yield 'a method named in a string, in another case' => ['$provider->{"createvirtualmachine"}($request);', true];
        yield 'a definition' => ['public function createVirtualMachine(CreateVmRequest $request): void {}', false];
        yield 'a comment' => ['// $provider->createVirtualMachine($request);', false];
        yield 'another method' => ['$provider->listNodes();', false];
    }

    #[Test]
    #[DataProvider('callerShapes')]
    public function the_caller_set_counts_every_way_a_build_method_is_named_whole(string $code, bool $reaches): void
    {
        $this->assertSame($reaches, $this->reachesABuildMethod($this->tokensOf("<?php\n".$code."\n")));
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
        yield 'the other build method, without cloud-init' => [$good."\n\$provider->reinstallVm('a', 'b', new ReinstallVmRequest(templateReference: 'x'));", false];
        yield 'the other build method, with cloud-init' => [$good."\n\$provider->reinstallVm('a', 'b', new ReinstallVmRequest(templateReference: 'x', cloudInit: new CloudInitConfig(sshKeys: [])));", true];
        yield 'the other build method, handed the wrong request' => [$good."\n\$provider->reinstallVm('a', 'b', new CreateVmRequest(cloudInit: new CloudInitConfig(sshKeys: [])));", false];
        yield 'the other build method in a callable array' => [$good."\n\$rebuild = [\$provider, 'reinstallVm'];", false];
        yield 'the same method in another case, without cloud-init' => [$good."\n\$provider->CreateVirtualMachine(new CreateVmRequest(nodeName: 'b'));", false];
        yield 'the other build method in another case, without cloud-init' => [$good."\n\$provider->reinstallvm('a', 'b', new ReinstallVmRequest(templateReference: 'x'));", false];
    }

    #[Test]
    #[DataProvider('cloudInitShapes')]
    public function the_cloud_init_pin_reads_arguments_and_not_text(string $code, bool $passes): void
    {
        $problems = $this->cloudInitFileProblems($this->tokensOf("<?php\n".$code."\n"), 'createVirtualMachine');

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
     * A production Proxmox provider row, tested by the real tester over a
     * faked wire and enabled by the real enablement — nothing written by hand.
     *
     * The chain is the one an operator walks: a touchable production machine,
     * a production credential, a production row, TestConnection, then
     * EnableProvider, which reassesses the row and refuses unless it is
     * ReadyForProduction. The endpoint is a private address rather than a
     * reserved name, because readiness refuses a production row pointed at a
     * name reserved for examples; stray requests are refused, so nothing
     * leaves the process whatever the address is.
     *
     * @param  list<string>  $privileges
     */
    private function provenProxmox(array $privileges): ProviderInstance
    {
        Http::preventStrayRequests();

        Http::fake([
            '*/api2/json/version*' => Http::response(['data' => ['version' => '8.2.4', 'release' => '8.2', 'repoid' => 'faa83925c9f0e5a3']]),
            '*/api2/json/nodes*' => Http::response(['data' => [['node' => 'pve-1', 'status' => 'online']]]),
            '*/api2/json/access/permissions*' => Http::response(['data' => [
                '/' => array_fill_keys($privileges, 1),
            ]]),
        ]);

        putenv(self::VARIABLE.'=lynomia@pve!control-plane=00000000-0000-0000-0000-000000000000');

        $server = ManagedServer::factory()->inProduction()->classified(SafetyClass::DiscoveryOnly)->create();

        $credential = CredentialReference::query()->create([
            'name' => 'f13-proxmox',
            'purpose' => 'A structurally valid Proxmox token that is the secret of nothing.',
            'environment' => DeploymentEnvironment::Production->value,
            'backend' => 'controller_environment',
            'backend_reference' => self::VARIABLE,
            'state' => 'configured',
        ]);

        $provider = ProviderInstance::query()->create([
            'name' => 'pve-f13',
            'category' => ProviderCategory::Compute->value,
            'driver' => 'proxmox',
            'environment' => DeploymentEnvironment::Production->value,
            'state' => ProviderState::Draft->value,
            'endpoint' => 'https://10.13.0.2:8006',
            'credential_reference_id' => $credential->getKey(),
            'managed_server_id' => $server->getKey(),
        ]);

        $record = app(TestConnection::class)->forProvider($provider);

        $this->assertTrue($record->result->usable(), 'The real tester did not reach a usable state, so nothing below is about capabilities.');

        $enabled = app(EnableProvider::class)->execute($provider->refresh(), User::factory()->create());

        $this->assertSame(ProviderState::Enabled, $enabled->state);
        $this->assertSame(ReadinessState::ReadyForProduction, $enabled->readiness);

        return $enabled->refresh();
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
     * Every PHP file of the application: everything under the control plane's
     * directory except what {@see self::NOT_APPLICATION_CODE} names and hidden
     * directories.
     *
     * @return array<string, string> path relative to the control plane => absolute path
     */
    private function sourceFiles(): array
    {
        $root = realpath(self::ROOT);
        $this->assertIsString($root);

        $keep = static function (SplFileInfo $file) use ($root): bool {
            $relative = substr($file->getPathname(), strlen($root) + 1);

            if (str_starts_with($file->getFilename(), '.')) {
                return false;
            }

            foreach (self::NOT_APPLICATION_CODE as $excluded) {
                if (str_starts_with($relative.'/', $excluded)) {
                    return false;
                }
            }

            return true;
        };

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), $keep)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
            }
        }

        ksort($files);

        // An empty or src-only walk would make both scans vacuous for the
        // code outside src/ that they claim to read.
        $this->assertArrayHasKey('src/Modules/Compute/Application/Actions/SyncClusterInventory.php', $files);
        $this->assertNotSame([], array_filter(array_keys($files), static fn (string $path): bool => str_starts_with($path, 'app/Console/')));
        $this->assertNotSame([], array_filter(array_keys($files), static fn (string $path): bool => str_starts_with($path, 'database/migrations/')));
        $this->assertSame([], array_filter(array_keys($files), static fn (string $path): bool => str_starts_with($path, 'tests/') || str_starts_with($path, 'vendor/')));

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
     * @return list<string> lower-cased, as PHP resolves a method name
     */
    private function storageRelations(): array
    {
        $relations = [];

        foreach ($this->sourceFiles() as $path) {
            $relations = [...$relations, ...$this->relationsIn($this->tokens($path))];
        }

        return array_values(array_unique($relations));
    }

    /**
     * The methods in this code that declare a relation able to create a
     * ComputeStorage row: hasMany, hasOne, morphMany, morphOne or
     * belongsToMany, with the model — as `::class` under any name it goes
     * by, or named in a string — as the related model, positional or named.
     * belongsTo and the *Through relations are left out: neither can create
     * the row it reads.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @return list<string> lower-cased, as PHP resolves a method name
     */
    private function relationsIn(array $tokens): array
    {
        $names = $this->modelNamesIn($tokens, $this->storageClasses());
        $relations = [];
        $method = null;

        foreach ($tokens as $i => $token) {
            if ($token[0] === T_FUNCTION && ($tokens[$i + 1][0] ?? null) === T_STRING) {
                $method = strtolower($tokens[$i + 1][1]);
            }

            if ($method === null
                || $token[0] !== T_STRING
                || ! in_array(strtolower($token[1]), ['hasmany', 'hasone', 'morphmany', 'morphone', 'belongstomany'], strict: true)
                || ($tokens[$i + 1][1] ?? null) !== '('
            ) {
                continue;
            }

            $related = ($tokens[$i + 2][1] ?? null) === 'related' && ($tokens[$i + 3][1] ?? null) === ':' ? $i + 4 : $i + 2;

            if ($this->classAsValue($tokens, $related, $names) !== null) {
                $relations[$method] = true;
            }
        }

        return array_keys($relations);
    }

    /**
     * Every class name the model is reached by in the application, derived
     * rather than listed: ComputeStorage itself, every class declared as
     * extending one of these (a subclass writes the same table), and every
     * factory whose `$model` is one of these — to a fixed point, so a
     * subclass of a subclass is found too.
     *
     * @return list<string> lower-cased short names
     */
    private function storageClasses(): array
    {
        if (self::$storageClasses !== null) {
            return self::$storageClasses;
        }

        $files = array_map(fn (string $path): array => $this->tokens($path), array_values($this->sourceFiles()));
        $classes = ['computestorage'];

        do {
            $before = $classes;

            foreach ($files as $tokens) {
                // An alias stays in its file; a class it declares does not.
                $declared = [];

                foreach ($tokens as $i => $token) {
                    if ($token[0] === T_CLASS && ($tokens[$i + 1][0] ?? null) === T_STRING) {
                        $declared[] = strtolower($tokens[$i + 1][1]);
                    }
                }

                $classes = array_values(array_unique([...$classes, ...array_intersect($this->modelNamesIn($tokens, $classes), $declared)]));
            }
        } while ($classes !== $before);

        return self::$storageClasses = $classes;
    }

    /**
     * The names the model goes by in this code: the ones given, every class
     * this code declares extending one of them or declaring one as a
     * factory's `$model`, and every alias it imports one of them under with
     * `use … as`.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  list<string>  $names  lower-cased short names
     * @return list<string>
     */
    private function modelNamesIn(array $tokens, array $names): array
    {
        do {
            $before = $names;
            $declared = null;

            foreach ($tokens as $i => $token) {
                if ($token[0] === T_CLASS && ($tokens[$i + 1][0] ?? null) === T_STRING) {
                    $declared = strtolower($tokens[$i + 1][1]);

                    if (($tokens[$i + 2][0] ?? null) === T_EXTENDS && $this->namesAny($tokens[$i + 3] ?? null, $names)) {
                        $names[] = $declared;
                    }
                }

                // protected $model = ComputeStorage::class;
                if ($declared !== null
                    && $token[0] === T_VARIABLE && $token[1] === '$model'
                    && ($tokens[$i + 1][1] ?? null) === '='
                    && $this->namesAny($tokens[$i + 2] ?? null, $names)
                    && ($tokens[$i + 3][0] ?? null) === T_DOUBLE_COLON
                ) {
                    $names[] = $declared;
                }

                if ($token[0] === T_AS && ($tokens[$i + 1][0] ?? null) === T_STRING && $this->namesAny($tokens[$i - 1] ?? null, $names)) {
                    $names[] = strtolower($tokens[$i + 1][1]);
                }
            }

            $names = array_values(array_unique($names));
        } while ($names !== $before);

        return $names;
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  list<string>  $relations  lower-cased
     */
    private function createsStorage(array $tokens, array $relations): bool
    {
        $names = $this->modelNamesIn($tokens, $this->storageClasses());
        $table = (new ComputeStorage)->getTable();
        $count = count($tokens);

        /*
         * Inside a file that declares the model, a subclass or its factory,
         * `self`, `static` and `parent` name it too.
         */
        $declaresModel = false;

        foreach ($tokens as $i => $token) {
            if ($token[0] === T_CLASS && ($tokens[$i + 1][0] ?? null) === T_STRING && in_array(strtolower($tokens[$i + 1][1]), $names, strict: true)) {
                $declaresModel = true;
            }
        }

        $isModel = fn (?array $token): bool => $this->namesAny($token, $names)
            || ($declaresModel && $token !== null && ($token[0] === T_STATIC || ($token[0] === T_STRING && in_array(strtolower($token[1]), ['self', 'parent'], strict: true))));

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // new ComputeStorage, and an anonymous class extending it
            if ($token[0] === T_NEW && ($isModel($tokens[$i + 1] ?? null) || $this->isAnonymousSubclass($tokens, $i + 1, $isModel))) {
                return true;
            }

            // SQL in a string that puts rows into the table, and
            // 'ComputeStorage::create' as a callable string.
            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], strict: true)
                && ($this->sqlWritesTable($token[1], $table) || $this->isStaticCreatorString($token[1], $names))
            ) {
                return true;
            }

            // [ComputeStorage::class, 'create']: the class as a value counts
            // only as the target of a callable array.
            $afterClass = $this->classAsValue($tokens, $i, $names);

            if ($afterClass !== null) {
                if ($this->isCreatorArgument($tokens, $afterClass)) {
                    return true;
                }

                continue;
            }

            $chainStart = null;

            // ComputeStorage::…
            if ($isModel($token) && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON) {
                $chainStart = $i + 2;
            }

            // DB::table('compute_storages')…, aliased or not
            if ($token[0] === T_STRING && strtolower($token[1]) === 'table'
                && ($tokens[$i + 1][1] ?? null) === '('
                && ($tokens[$i + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
                && preg_match('/^'.preg_quote($table, '/').'(\s+as\s+\w+)?$/i', trim($tokens[$i + 2][1], '\'"')) === 1
            ) {
                $chainStart = $i + 1;
            }

            // ->storages()… or ->storages->…
            if (in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], strict: true)
                && ($tokens[$i + 1][0] ?? null) === T_STRING
                && in_array(strtolower($tokens[$i + 1][1]), $relations, strict: true)
                && in_array($tokens[$i + 2][1] ?? null, ['(', '->', '?->'], strict: true)
            ) {
                $chainStart = $i + 2;
            }

            if ($chainStart === null) {
                continue;
            }

            $chain = $this->chain($tokens, $chainStart);

            if ($this->chainCreates($chain['names']) || $this->isCreatorArgument($tokens, $chain['end'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is the code after `new` at $i `class(…) extends <the model>`?
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  callable(array{0: int|string, 1: string, 2: int}|null): bool  $isModel
     */
    private function isAnonymousSubclass(array $tokens, int $i, callable $isModel): bool
    {
        if (($tokens[$i][0] ?? null) !== T_CLASS) {
            return false;
        }

        $next = $i + 1;

        if (($tokens[$next][1] ?? null) === '(') {
            $next = $this->closing($tokens, $next) + 1;
        }

        return ($tokens[$next][0] ?? null) === T_EXTENDS && $isModel($tokens[$next + 1] ?? null);
    }

    /**
     * Does this chain of method names make a row?
     *
     * Every creator does, with one exception: a row found by its id,
     * filled or not, and saved or pushed — that writes the row that was
     * found. Anything else after the find turns the chain back into one that
     * can create: `findOrFail($id)->replicate()->save()` makes a second row.
     *
     * @param  list<string>  $names
     */
    private function chainCreates(array $names): bool
    {
        $found = false;

        foreach ($names as $name) {
            $name = strtolower($name);

            if (in_array($name, ['find', 'findorfail', 'findmany'], strict: true)) {
                $found = true;

                continue;
            }

            if ($found && (in_array($name, ['fill', 'forcefill'], strict: true) || preg_match('/^(save|push)/', $name) === 1)) {
                continue;
            }

            if (in_array($name, $this->creatorMethods(), strict: true)) {
                return true;
            }

            $found = false;
        }

        return false;
    }

    /**
     * Every method that makes a row, or an instance that saving makes into
     * one, read off the framework's builders, relations and model by
     * reflection rather than listed — so a framework upgrade that adds one is
     * counted without anybody remembering to.
     *
     * @return list<string> lower-cased, as PHP resolves a method name
     */
    private function creatorMethods(): array
    {
        if ($this->creatorMethods !== null) {
            return $this->creatorMethods;
        }

        $creators = [];

        foreach ([EloquentBuilder::class, QueryBuilder::class, HasMany::class, HasOne::class, MorphMany::class, BelongsToMany::class, Model::class, Factory::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $name = $method->getName();

                if (preg_match('/^(create|forceCreate|fillAndInsert|insert|upsert|save|push|replicate|make)|Or(Create|New|Insert)$|^new(Model)?Instance$/', $name) === 1
                    // Not creators: event registration, a column name, visibility.
                    && preg_match('/^(created|saved|createdAt|make(Hidden|Visible))/', $name) !== 1
                ) {
                    $creators[] = strtolower($name);
                }
            }
        }

        $creators = array_values(array_unique($creators));

        // The derivation must at least find the ones this file names.
        foreach (['create', 'insert', 'upsert', 'save', 'make', 'replicate', 'firstorcreate', 'firstornew', 'updateorinsert', 'newinstance'] as $expected) {
            $this->assertContains($expected, $creators, 'The creator methods were not derived, so the writer scan proves nothing.');
        }

        return $this->creatorMethods = $creators;
    }

    /**
     * If the model is named as a value at $i — `ComputeStorage::class` under
     * any name it goes by, or its name in a string — where that value ends.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  list<string>  $names  lower-cased short names
     */
    private function classAsValue(array $tokens, int $i, array $names): ?int
    {
        $token = $tokens[$i] ?? null;

        if ($token === null) {
            return null;
        }

        if ($this->namesAny($token, $names) && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON && ($tokens[$i + 2][0] ?? null) === T_CLASS) {
            return $i + 3;
        }

        if ($token[0] === T_CONSTANT_ENCAPSED_STRING
            && preg_match('/^[\'"]\\\\*([\w\\\\]*\\\\)?(\w+)[\'"]$/', $token[1], $match) === 1
            && in_array(strtolower($match[2]), $names, strict: true)
        ) {
            return $i + 1;
        }

        return null;
    }

    /**
     * Is the token after $end `, '<creator>'` — a callable array's method?
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     */
    private function isCreatorArgument(array $tokens, int $end): bool
    {
        return ($tokens[$end][1] ?? null) === ','
            && ($tokens[$end + 1][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
            && in_array(strtolower(trim($tokens[$end + 1][1], '\'"')), $this->creatorMethods(), strict: true);
    }

    /**
     * Is this string `'…ComputeStorage::create'` — a callable string naming a
     * creator on the model?
     *
     * @param  list<string>  $names  lower-cased short names
     */
    private function isStaticCreatorString(string $literal, array $names): bool
    {
        return preg_match('/(?:^|\\\\)(\w+)::(\w+)$/', trim($literal, '\'"'), $match) === 1
            && in_array(strtolower($match[1]), $names, strict: true)
            && in_array(strtolower($match[2]), $this->creatorMethods(), strict: true);
    }

    /**
     * Is this token one of these class names, however qualified, in any case
     * — as PHP resolves a class name?
     *
     * @param  array{0: int|string, 1: string, 2: int, 3?: int}|null  $token
     * @param  list<string>  $names  lower-cased short names
     */
    private function namesAny(?array $token, array $names): bool
    {
        if ($token === null || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], strict: true)) {
            return false;
        }

        $parts = explode('\\', $token[1]);

        return in_array(strtolower((string) end($parts)), $names, strict: true);
    }

    /**
     * SQL, written in a string, that puts rows into the table: INSERT or MERGE
     * INTO it, or COPY it FROM somewhere — the name bare, quoted, escaped or
     * schema-qualified.
     */
    private function sqlWritesTable(string $text, string $table): bool
    {
        $quote = '(?:\\\\?["`])?';
        $name = $quote.'(?:\w+'.$quote.'\.'.$quote.')?'.preg_quote($table, '/').'\b';

        return preg_match('/\binto\s+'.$name.'/i', $text) === 1
            || preg_match('/\bcopy\s+'.$name.'[^;]*?\bfrom\b/is', $text) === 1;
    }

    /**
     * The method names called along a fluent chain starting at $i, skipping
     * each call's arguments, and where the chain ends. A method named in a
     * literal, `->{'create'}(`, is that method.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @return array{names: list<string>, end: int}
     */
    private function chain(array $tokens, int $i): array
    {
        $names = [];
        $count = count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            if ($token[1] === '(') {
                $i = $this->closing($tokens, $i) + 1;

                continue;
            }

            // A method name; after `::` a reserved word such as `new` is one
            // too, and the tokenizer does not say so.
            $named = $token[0] === T_STRING
                || (in_array($tokens[$i - 1][0] ?? null, [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], strict: true)
                    && preg_match('/^[a-z_]\w*$/i', $token[1]) === 1);

            if ($named && ($tokens[$i + 1][1] ?? null) === '(') {
                $names[] = $token[1];
                $i++;

                continue;
            }

            if ($token[1] === '{'
                && ($tokens[$i + 1][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
                && ($tokens[$i + 2][1] ?? null) === '}'
                && ($tokens[$i + 3][1] ?? null) === '('
            ) {
                $names[] = trim($tokens[$i + 1][1], '\'"');
                $i += 3;

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

        return ['names' => $names, 'end' => $i];
    }

    /**
     * Every build method, checked in one file: each call to any of them must
     * hand the adapter its own request carrying cloud-init; none may be
     * dispatched through a variable or named in a string; and the file's own
     * method must be called by name at least once — the backstop.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @return array{found: int, missing: list<string>, dynamic: list<int>}
     */
    private function cloudInitFileProblems(array $tokens, string $own): array
    {
        $found = 0;
        $missing = [];
        $dynamic = [];

        foreach (self::BUILD_METHODS as $method => $build) {
            $problems = $this->cloudInitProblems($tokens, $method, $build['request']);

            foreach ($problems['missing'] as $line) {
                $missing[] = sprintf('%s at line %d', $method, $line);
            }

            $dynamic = [...$dynamic, ...$problems['dynamic']];

            if ($method === $own) {
                $found = $problems['found'];
            }
        }

        $dynamic = array_values(array_unique($dynamic));
        sort($dynamic);

        return ['found' => $found, 'missing' => $missing, 'dynamic' => $dynamic];
    }

    /**
     * Does this code name a build method whole — called, or taken as a
     * first-class callable, in any case; or named in a string, as a callable
     * array, `call_user_func` or `->{'…'}(` name one? The same two readings
     * the pin makes inside the pinned files, made of every file.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     */
    private function reachesABuildMethod(array $tokens): bool
    {
        foreach ($tokens as $i => $token) {
            foreach (array_keys(self::BUILD_METHODS) as $method) {
                if ($this->callsMethod($tokens, $i, $method) || $this->stringNames($token, $method)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Is every build method this code reaches called on the fake hypervisor,
     * constructed by class in the call itself — `(new FakeComputeProvider)->…(`
     * — with that name imported as the fake and nothing else, or written
     * fully qualified? A build method named in a string, or called on
     * anything else, including a variable holding the fake, is a no.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     */
    private function callsOnlyTheFake(array $tokens): bool
    {
        $fake = FakeComputeProvider::class;
        $short = (new ReflectionClass($fake))->getShortName();
        $imported = false;

        foreach ($tokens as $i => $token) {
            if ($token[0] === T_USE
                && in_array($tokens[$i + 1][0] ?? null, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], strict: true)
                && strcasecmp(ltrim($tokens[$i + 1][1], '\\'), $fake) === 0
                && ($tokens[$i + 2][1] ?? null) === ';'
            ) {
                $imported = true;
            }
        }

        foreach ($tokens as $i => $token) {
            foreach (array_keys(self::BUILD_METHODS) as $method) {
                if ($this->stringNames($token, $method)) {
                    return false;
                }

                if (! $this->callsMethod($tokens, $i, $method)) {
                    continue;
                }

                // `(new Fake)->` or `(new Fake())->`: $close is the parenthesis
                // that closes the construction.
                $close = $i - 2;

                if (($tokens[$i - 1][0] ?? null) !== T_OBJECT_OPERATOR || ($tokens[$close][1] ?? null) !== ')') {
                    return false;
                }

                if (($tokens[$close - 1][1] ?? null) === ')' && ($tokens[$close - 2][1] ?? null) === '(') {
                    $close -= 2;
                }

                $class = $tokens[$close - 1] ?? null;

                if (($tokens[$close - 2][0] ?? null) !== T_NEW || ($tokens[$close - 3][1] ?? null) !== '(' || $class === null) {
                    return false;
                }

                $isFake = ($class[0] === T_NAME_FULLY_QUALIFIED && strcasecmp(ltrim($class[1], '\\'), $fake) === 0)
                    || ($imported && $class[0] === T_STRING && strcasecmp($class[1], $short) === 0);

                if (! $isFake) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Is the token at $i a call of this method, in any case, as PHP matches
     * a method name?
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     */
    private function callsMethod(array $tokens, int $i, string $method): bool
    {
        return $tokens[$i][0] === T_STRING && strcasecmp($tokens[$i][1], $method) === 0 && $this->isCall($tokens, $i);
    }

    /**
     * Is this token a string whose content is the method's name — alone or as
     * `Class::name`, in any case — the way PHP reads a callable?
     *
     * @param  array{0: int|string, 1: string, 2: int}  $token
     */
    private function stringNames(array $token, string $method): bool
    {
        return in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], strict: true)
            && preg_match('/(^|::)'.preg_quote($method, '/').'$/i', trim($token[1], " \t\n\r\0\x0B'\"")) === 1;
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

            if ($this->stringNames($token, $method)) {
                $dynamic[] = $token[2];
            }

            if (! $this->callsMethod($tokens, $i, $method)) {
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
     * Is this token the class name, however qualified, in any case — as PHP
     * resolves a class name?
     *
     * @param  array{0: int|string, 1: string, 2: int, 3?: int}|null  $token
     */
    private function names(?array $token, string $class): bool
    {
        return $this->namesAny($token, [strtolower($class)]);
    }
}
