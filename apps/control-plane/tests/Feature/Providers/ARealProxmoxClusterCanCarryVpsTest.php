<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
 *  4. `inventory_sync`. On the tester's reading of the Proxmox API — which
 *     nothing in this repository verifies against a cluster — a token short
 *     of `Datastore.Audit` reads no storage pools, so the inventory sync
 *     records none, and the scheduler, which places only on recorded pools,
 *     places nothing. The tester maps `templates` to the same privilege, so
 *     such a token is refused either way;
 *     `inventory_sync` is the requirement that names why placement fails,
 *     and keeps the refusal if `templates` is ever settled some other way.
 *     It is a capability in four places (the category, the Proxmox
 *     catalogue entry, the VPS requirement and the privilege map) and this
 *     file breaks if the four stop agreeing; the architecture gate cannot
 *     see all four removed together.
 *  5. The two premises the map rests on, each checked at source by a token
 *     scan. What each scan reads is stated where it is defined, and what it
 *     does not read is measured rather than bounded (see THE SOURCE-READING
 *     GATES below):
 *     - `create` and `reinstall` ask for `VM.Config.Cloudinit` because the
 *       calls that build or rebuild a machine on a real hypervisor hand the
 *       adapter a cloud-init config. The cloud-init pin reads, token by
 *       token, each call to either build method written whole in each
 *       handler — not only the handler's own method — and requires one
 *       positional argument to be exactly `new <Request>(…)`, among whose
 *       own arguments `cloudInit:` is bound to exactly `new
 *       CloudInitConfig(…)`: nothing called on or applied to either
 *       construction after it closes, and a comment or a string that merely
 *       quotes `cloudInit:` does not satisfy it. The caller set tokenizes the
 *       files READ BY THE SCANS and fails if any of them other than the two
 *       handlers names a build method whole — called, taken as a callable,
 *       or named in a string alone, after `::` or after `@`, the forms PHP
 *       and Laravel call — apart from one browser-suite seeder whose every
 *       such call is checked to be made on the fake hypervisor. And the map
 *       is held to its half: both build capabilities still ask for
 *       `VM.Config.Cloudinit`.
 *     - `inventory_sync` is load-bearing because, of the code the writer
 *       scan reads, only the inventory sync brings a ComputeStorage row into
 *       existence in production; the simulation loader is the one other
 *       creator, and it refuses production. The shapes the scan counts, and
 *       the escapes found so far with the number of sites each occupies
 *       today, are listed at the test.
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
 *  - Lists the tester's own reading makes short, recorded in its docblock
 *    rather than fixed: `suspend` and `unsuspend` map to `VM.PowerMgmt`
 *    alone while the adapter also writes `onboot` and `lock`
 *    (`VM.Config.Options`); `create` and `reinstall` send `import-from=`,
 *    which the tester reads as needing `Datastore.Audit`, and do not list
 *    it. No verdict moves on either, because both compute products require
 *    `create`, which requires `VM.Config.Options`, and `templates`, which
 *    requires `Datastore.Audit`; the tester's answer for those capabilities
 *    is still wrong for such a token.
 *
 * ===========================================================================
 * THE SOURCE-READING GATES: WHAT THEY READ, AND WHAT IS MEASURED INSTEAD
 * ===========================================================================
 *
 * The cloud-init pin, the caller set and the writer scan read source text to
 * predict what will run. Each reads the grammar stated where it is defined;
 * none of them bounds what PHP and Laravel can execute, and no list in this
 * file of what they miss is closed. The escapes named here and at the writer
 * scan are the ones attack has found so far, and an attacker who stops has
 * found the limit of the attack, not of the scan. What can be measured is
 * how many sites of each escape the tree holds, and that is given below with
 * the command that measured it, at the commit that last changed this file.
 * Re-run them when the tree moves: a green run relies on those counts, not on
 * the lists.
 *
 * READ BY THE SCANS: each file under the control plane whose name ends
 * `.php`, outside {@see self::NOT_APPLICATION_CODE} and hidden directories,
 * through PHP's own tokenizer — 1,595 files, which is also what
 *
 *     G -l '' . | wc -l
 *
 * counts, where G, here and below, is run from apps/control-plane and stands
 * for `grep -rE --include='*.php' --exclude-dir={tests,vendor,node_modules,storage,tools,cache}`.
 *
 * ESCAPES FOUND, AND THEIR OCCUPANCY:
 *
 *  - Code a Blade template writes in its own syntax (`@php … @endphp`,
 *    `{{ … }}`, a directive's argument) reaches the tokenizer as text, so no
 *    scan reads it. 2 templates, and 0 name a build method, the model, a
 *    relation to it or its table:
 *
 *        grep -rilE --include='*.blade.php' --exclude-dir={vendor,node_modules} 'virtualmachine|reinstallvm|computestorage|compute_storages|storages?|assignable' .
 *
 *  - PHP in a file whose name does not end `.php` is not read. 1 such file,
 *    `artisan`, which names none of those:
 *
 *        grep -rlE --exclude='*.php' --exclude-dir={tests,vendor,node_modules,storage,tools,cache} '^(<\?php|#!.*php)' .
 *        grep -ciE 'virtualmachine|reinstallvm|computestorage|compute_storages|storages?|assignable' artisan
 *
 *  - A build method reached through a name assembled at run time is
 *    invisible to the pin and the caller set. The dispatch forms that carry
 *    one — a method called through a variable or a braced expression, and
 *    call_user_func / forward_static_call — occur at 0 sites:
 *
 *        G -n '(->|::)\s*(\$\w+|\{[^}]*\})\s*\(' .
 *        G -nw 'call_user_func(_array)?|forward_static_call(_array)?' .
 *
 *    A callable array or string whose name is concatenated and handed to
 *    something that calls it is not in that count; no grep pins that down.
 *    What bounds this escape besides its occupancy is the per-file backstop:
 *    each pinned file must still call its own method by name, so a hidden
 *    call site can add a machine without cloud-init; it cannot take
 *    cloud-init off the call the backstop sees.
 *  - The writer scan's own escapes are listed at WHAT IT DOES NOT COUNT, each
 *    with its occupancy.
 */
final class ARealProxmoxClusterCanCarryVpsTest extends TestCase
{
    use RefreshDatabase;

    private const string VARIABLE = 'LYNOMIA_TEST_F13_PROXMOX_TOKEN';

    private const string ROLE_FILE = __DIR__.'/../../../../../infrastructure/ansible/group_vars/proxmox.yml';

    /**
     * The control plane's own directory. The scans below tokenize each file
     * under it whose name ends `.php`, outside NOT_APPLICATION_CODE (the
     * tests, the dependencies, what the framework writes at run time and the
     * developer tools) and hidden directories — so a console command, a
     * migration or a seeder is read as well as src/. What that leaves unread,
     * and how much of it the tree holds, is in the file header.
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
     * Two files. {@see self::the_caller_set_finds_a_build_method_named_whole_only_in_the_pinned_handlers()}
     * fails if any other file it reads names a build method whole — apart
     * from {@see self::FAKE_ONLY_CALLERS}, whose every such call it checks is
     * made on the fake — so a caller written in the forms it reads cannot be
     * added without this list changing. It is not a closed list: the forms
     * the caller set does not read, and the number of sites each occupies
     * today, are in the file header.
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
     * Each build call they write is made on the fake hypervisor, constructed
     * by class in the call itself, so no cluster is asked for a privilege and
     * the premise is not about them. The caller-set test checks the receiver
     * of each call it reads rather than trusting the reason.
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

    /** @var list<string>|null derived once per process, like the storage classes */
    private static ?array $relationClassNames = null;

    /** @var array{kinds: array<string, string|null>, classes: list<string>}|null */
    private static ?array $relationGrammar = null;

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
    public function the_writer_scan_finds_only_the_inventory_sync_and_the_simulation_loader_creating_storage_rows(): void
    {
        /*
         * WHAT THIS CHECK COUNTS. The files READ BY THE SCANS (file header)
         * are tokenized: src/, app/, database/, routes/, config/ and the rest
         * of the `.php` files, not tests/ or vendor/. Comments are removed by
         * the tokenizer, so a comment can neither match nor hide a creation.
         * A string literal is one token, read only where a shape below reads
         * one — as SQL, a callable, a table name, a `$table` default, or a
         * name in a relation declaration: a sentence quoting
         * `ComputeStorage::create(…)` matches nothing, and one quoting SQL
         * that inserts into the table is counted, which fails closed. What a
         * double-quoted string interpolates, `"{$node->storages()->create()}"`,
         * is code and is read as code. A file creates a ComputeStorage row
         * when, in its code, any of these appears:
         *
         *   ComputeStorage::<creator>(             static creator
         *   ComputeStorage::query()->…-><creator>( a query-built creator,
         *                                          whatever comes before it on
         *                                          the chain: calls, `::`
         *                                          calls, indexes (`get()[0]`)
         *                                          and property reads (`->map`)
         *   ->storages()->…-><creator>(            a relation, called, read as
         *   ->storages->…-><creator>(              a property or indexed, its
         *   ->storages[0]->…-><creator>(           name written or in a literal
         *                                          `->{'…'}`; its names
         *                                          derived, not listed
         *                                          (see relationsIn()): each
         *                                          relation kind the framework's
         *                                          Model documents, belongsTo,
         *                                          the *Through and morph kinds
         *                                          included, one built by hand,
         *                                          one narrowed from another,
         *                                          and a has-through
         *   ->table('compute_storages')->…-><creator>(
         *   ->from('compute_storages')->…-><creator>(
         *                                          the table, bypassing the
         *                                          model, aliased or
         *                                          schema-qualified or not
         *   new ComputeStorage                     an instance, saved or not
         *   [ComputeStorage::class, '<creator>']   a callable array, on the
         *   [<a chain above>, '<creator>']         class (or its name in a
         *                                          string) or on a chain
         *   '…ComputeStorage::<creator>'           a callable string, in PHP's
         *   '…ComputeStorage@<creator>'            form or Laravel's
         *   'INSERT INTO compute_storages …'       SQL in a string: INSERT or
         *                                          MERGE INTO, or COPY … FROM
         *
         * ComputeStorage stands for these names, derived from the files rather
         * than listed (see storageClasses()): the class however qualified and
         * in any case; a named class the files declare extending one of these
         * names, giving `$table` the model's table as a literal in its body
         * (bare or schema-qualified — a second model on the same table), or
         * naming one as a factory's `$model`, to a fixed point; an alias a
         * file imports any of those under with `use … as`; and `self`,
         * `static` and `parent` inside a file that declares one of them.
         * `new class extends ComputeStorage`, and an anonymous class whose
         * body gives `$table` the table, count as an instance.
         *
         * <creator> is each method the framework's builders, relations
         * (every class the model's relation-declaring methods return), model
         * and factories offer whose name has one of the shapes of a method
         * that makes a row, or an instance that saving makes into one —
         * create…, insert…, upsert, …OrCreate, …OrNew, …OrInsert, save…,
         * push…, make…, replicate…, newInstance — read off those classes by
         * reflection rather than listed, matched in any case as PHP matches a
         * method name, and read through a literal `->{'…'}(` too.
         *
         * One chain is not a creator: a row found by its id (find, findOrFail,
         * findMany), filled or not, and saved or pushed. That writes the row
         * that was found. Anything else after the find — replicate,
         * newInstance, a create forwarded through the model — is a creator
         * again. ReserveNodeCapacity and ReleaseNodeCapacity lock a row by id
         * and write it through a variable, so nothing in their chains creates
         * one; the two verbatim lines are among the shapes below.
         *
         * WHAT IT DOES NOT COUNT. These are the escapes attack has found, not
         * the boundary of what the scan misses. Each is given with the number
         * of sites it occupies in the tree, measured with the command shown
         * (G as in the file header), at the commit that last changed this
         * file:
         *
         *  - Anything reached through a variable: one holding the class name,
         *    a query, a relation, an instance or a method name, `$this`
         *    included (`$query->create(…)`, `$storage->replicate()->save()`,
         *    `->$method(…)`), and a relation declared from one
         *    (`$this->newHasMany($instance->newQuery(), …)`). A value of the
         *    model put in a variable: 5 sites. One is in SyncClusterInventory,
         *    a listed creator; ReserveNodeCapacity and ReleaseNodeCapacity save
         *    the row they found by id; NodeScheduler and MappingChain read.
         *
         *        G -n '\$\w+\s*=\s*\\?(\w+\\)*ComputeStorage\s*::' . | grep -v '::class'
         *
         *    A relation to it put in a variable: 0 sites (the one line the
         *    command prints is the simulation loader calling its own
         *    `storage()` method). ComputeStorage itself calls nothing on
         *    `$this` but two relation declarations and its own `freeGib()`.
         *
         *        G -n '=\s*\$\w+\s*(->|\?->)\s*(storages?|assignable)\s*\(' .
         *        grep -nE '\$this\s*->\s*\w+\s*\(' src/Modules/Compute/Infrastructure/Models/ComputeStorage.php
         *
         *  - The class name as a value anywhere other than a callable, a
         *    relation declaration or a factory's `$model`
         *    (`app(ComputeStorage::class)`, `class_alias(…)`), and a mixin
         *    (`Builder::mixin(…)`), whose methods become macros only at run
         *    time. `ComputeStorage::class` occurs at 4 sites, each a factory's
         *    `$model` or a relation declaration, which the scan reads;
         *    `class_alias` and `::mixin(` at 0.
         *
         *        G -n 'ComputeStorage(Factory)?::class' .
         *        G -n 'class_alias|::mixin\(' .
         *
         *  - The table reached other than as a literal in `table()` or
         *    `from()`, in SQL in a string, or as a literal `$table` in a class
         *    body: a table name held in a constant or a variable,
         *    `setTable(…)`, a `getTable()` override, a `$table` a trait or a
         *    constructor supplies. The table's name occurs in 5 files: three
         *    migrations (schema; the scan reads them and counts none), a
         *    comment in the reference topology, and NamingConcept::table(),
         *    whose one caller reads through `DB::table($concept->table())
         *    ->get(…)`. `setTable(` and a `getTable()` override occur at 0.
         *
         *        G -il 'compute_storages' .
         *        G -n 'setTable\(|function getTable\(' .
         *
         *  - Any name or SQL assembled at run time: the dispatch forms counted
         *    in the file header (0 sites), and the table name above.
         *  - Code a Blade template writes in its own syntax, and PHP in a file
         *    not named `.php`: see the file header (0 sites that name the
         *    model, a relation to it or its table).
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
            "The set of code the writer scan finds creating a storage row has changed. inventory_sync is required of VPS because, of the code this scan reads, only the sync creates one in production; a new creator has to be justified here:\n  %s",
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
        yield 'found rows indexed, replicated and saved' => ['ComputeStorage::query()->whereKey($id)->get()[0]->replicate()->save();', true];
        yield 'found rows through a higher-order proxy' => ['ComputeStorage::query()->whereKey($id)->get()->map->replicate()->each->save();', true];
        yield 'a relation read as a property and indexed' => ['$node->storages[0]->replicate()->save();', true];
        yield 'a static call on what the chain returned' => ['ComputeStorage::query()->first()::create([]);', true];
        yield 'a property named in a literal, then a creator' => ["ComputeStorage::query()->get()->{'map'}->replicate()->each->save();", true];
        yield 'a relation named in a literal' => ["\$node->{'storages'}()->create([]);", true];
        yield 'the table, schema-qualified' => ["DB::table('public.compute_storages')->insert([]);", true];
        yield 'the table named in from()' => ["DB::query()->from('compute_storages')->insert([]);", true];
        yield 'a callable string in Laravel\'s form' => ["app()->call('Lynomia\\Modules\\Compute\\Infrastructure\\Models\\ComputeStorage@create', []);", true];
        yield 'code interpolated into a string' => ['$label = "{$node->storages()->create([])}";', true];
        yield 'a message quoting SQL that inserts into the table, which fails closed' => ["throw new \\RuntimeException('Never INSERT INTO compute_storages from a controller');", true];
        yield 'found rows indexed, only read' => ['ComputeStorage::query()->get()[0]->name;', false];
        yield 'a relation indexed, only read' => ['$node->storages[0]->name;', false];
        yield 'the table named in from(), only read' => ["DB::query()->from('public.compute_storages')->get();", false];
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

        // A second model on the same table, named by `$table`.
        yield 'a second model on the table' => ["final class StoragePoolRecord extends Model {\n    use HasUlids;\n    protected \$table = 'compute_storages';\n    protected \$guarded = ['id'];\n}\nStoragePoolRecord::query()->create([]);", true];
        yield 'a second model on the table, schema-qualified' => ["class PoolRow extends Model { protected \$table = \"public.compute_storages\"; }\nPoolRow::forceCreate([]);", true];
        yield 'a subclass of a second model on the table' => ["class PoolRow extends Model { protected \$table = 'compute_storages'; }\nclass FastPoolRow extends PoolRow {}\n(new FastPoolRow)->save();", true];
        yield 'static, inside a second model on the table' => ["class PoolRow extends Model {\n    protected \$table = 'compute_storages';\n    public static function adopt(): static { return static::create([]); }\n}", true];
        yield 'an anonymous model on the table' => ["(new class extends Model { protected \$table = 'compute_storages'; })->save();", true];
        yield 'an anonymous model on the table, constructed with arguments' => ["\$pool = new class(['name' => 'x']) extends Model { protected \$table = 'compute_storages'; };", true];
        yield 'a second model on the table, only read' => ["class PoolRow extends Model { protected \$table = 'compute_storages'; }\nPoolRow::query()->where('a', 1)->get();", false];
        yield 'a model on another table' => ["class NodeRow extends Model { protected \$table = 'compute_nodes'; }\nNodeRow::query()->create([]);", false];
        yield 'an anonymous model on another table' => ["(new class extends Model { protected \$table = 'compute_nodes'; })->save();", false];
        yield 'a model on another table, next to one that names the table in a string' => ["class NodeRow extends Model { protected \$table = 'compute_nodes'; }\nNodeRow::create([]);\n\$label = 'compute_storages';", false];
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
        yield 'another model' => ['public function nodes(): HasMany { return $this->hasMany(ComputeNode::class); }', []];

        // Each kind the relation grammar derives from the model: each can create the row it reads.
        yield 'belongsTo, whose create is forwarded to the related model' => ['public function storage(): BelongsTo { return $this->belongsTo(ComputeStorage::class); }', ['storage']];
        yield 'morphOne' => ["public function pool(): MorphOne { return \$this->morphOne(ComputeStorage::class, 'owner'); }", ['pool']];
        yield 'morphToMany' => ["public function pools(): MorphToMany { return \$this->morphToMany(ComputeStorage::class, 'owner'); }", ['pools']];
        yield 'morphedByMany' => ["public function pools(): MorphToMany { return \$this->morphedByMany(ComputeStorage::class, 'owner'); }", ['pools']];
        yield 'hasManyThrough to the model' => ['public function pools(): HasManyThrough { return $this->hasManyThrough(ComputeStorage::class, ComputeNode::class); }', ['pools']];
        yield 'hasOneThrough to the model' => ['public function pool(): HasOneThrough { return $this->hasOneThrough(ComputeStorage::class, ComputeNode::class); }', ['pool']];
        yield 'hasManyThrough to the model, named after the intermediate' => ['public function pools(): HasManyThrough { return $this->hasManyThrough(through: ComputeNode::class, related: ComputeStorage::class); }', ['pools']];
        yield 'hasManyThrough with the model as the intermediate only' => ['public function snapshots(): HasManyThrough { return $this->hasManyThrough(Snapshot::class, ComputeStorage::class); }', []];
        yield 'hasManyThrough with the model as the intermediate, named first' => ['public function snapshots(): HasManyThrough { return $this->hasManyThrough(through: ComputeStorage::class, related: Snapshot::class); }', []];
        yield 'morphTo, which reads the related class off the row' => ['public function owner(): MorphTo { return $this->morphTo(); }', ['owner']];
        yield 'self, inside the model' => ["class ComputeStorage extends Model {\n    public function children(): HasMany { return \$this->hasMany(self::class, 'parent_id'); }\n}", ['children']];
        yield 'to a second model on the table' => ["class PoolRow extends Model { protected \$table = 'compute_storages'; }\npublic function pools(): HasMany { return \$this->hasMany(PoolRow::class); }", ['pools']];

        // Built by hand.
        yield 'a factory method, from the model\'s query' => ["public function pools(): HasMany { return \$this->newHasMany(ComputeStorage::query(), \$this, 'node_id', 'id'); }", ['pools']];
        yield 'constructed' => ["public function pools(): HasMany { return new HasMany(ComputeStorage::query(), \$this, 'node_id', 'id'); }", ['pools']];
        yield 'constructed under an imported alias' => ["use Illuminate\\Database\\Eloquent\\Relations\\HasMany as Many;\npublic function pools(): Many { return new Many(ComputeStorage::query(), \$this, 'node_id', 'id'); }", ['pools']];
        yield 'constructed from a subclass this code declares' => ["class PoolRelation extends HasMany {}\npublic function pools(): PoolRelation { return new PoolRelation(query: ComputeStorage::query(), parent: \$this, foreignKey: 'node_id', localKey: 'id'); }", ['pools']];
        yield 'constructed for another model' => ["public function nodes(): HasMany { return new HasMany(ComputeNode::query(), \$this, 'cluster_id', 'id'); }", []];

        // Reached through a relation already known.
        $storages = "public function storages(): HasMany { return \$this->hasMany(ComputeStorage::class); }\n";

        yield 'narrowed from a relation' => [$storages."public function activeStorages(): HasMany { return \$this->storages()->where('enabled', true); }", ['storages', 'activestorages']];
        yield 'narrowed from a relation, parenthesised' => [$storages."public function activeStorages(): HasMany { return (\$this->storages())->where('enabled', true); }", ['storages', 'activestorages']];
        yield 'a relation used, not returned' => [$storages."public function freeGib(): int { \$pools = \$this->storages()->get(); return \$pools->sum('free_gib'); }", ['storages']];
        yield 'an accessor reading a relation' => [$storages.'protected function pools(): Attribute { return Attribute::get(fn () => $this->storages); }', ['storages', 'pools']];
        yield 'an accessor in the older form' => [$storages.'public function getFreePoolsAttribute() { return $this->storages; }', ['storages', 'getfreepoolsattribute', 'freepools']];
        yield 'a has-through, by name' => [$storages."public function clusterPools(): HasManyThrough { return \$this->through('nodes')->has('storages'); }", ['storages', 'clusterpools']];
        yield 'a has-through, by magic' => [$storages.'public function clusterPools(): HasManyThrough { return $this->throughNodes()->hasStorages(); }', ['storages', 'clusterpools']];
        yield 'a has-through, by closure' => [$storages."public function clusterPools(): HasManyThrough { return \$this->through('nodes')->has(fn (ComputeNode \$node) => \$node->storages()); }", ['storages', 'clusterpools']];
        yield 'a has-through with the model as the intermediate' => [$storages."public function snapshots(): HasManyThrough { return \$this->through('storages')->has('snapshots'); }", ['storages']];
        yield 'narrowed from a relation to another model' => ["public function nodes(): HasMany { return \$this->hasMany(ComputeNode::class); }\npublic function onlineNodes(): HasMany { return \$this->nodes()->where('online', true); }", []];

        // Registered at boot under a name written whole.
        yield 'resolveRelationUsing' => ["ComputeNode::resolveRelationUsing('pools', fn (\$node) => \$node->hasMany(ComputeStorage::class, 'node_id'));", ['pools']];
        yield 'a macro' => ["EloquentBuilder::macro('pools', fn () => ComputeStorage::query());", ['pools']];
        yield 'a macro for another model' => ["EloquentBuilder::macro('nodes', fn () => ComputeNode::query());", []];
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('relationShapes')]
    public function the_relation_names_are_derived_from_each_declaration_shape_the_scan_claims(string $code, array $expected): void
    {
        $this->assertSame($expected, $this->relationsIn($this->tokensOf("<?php\n".$code."\n")));
    }

    // -----------------------------------------------------------------------
    // 5. The premise behind VM.Config.Cloudinit: the build calls carry cloud-init
    // -----------------------------------------------------------------------

    #[Test]
    public function each_build_call_the_pin_reads_in_the_handlers_hands_the_adapter_a_cloud_init_config(): void
    {
        foreach (self::CLOUD_INIT_CALL_SITES as $relative => $own) {
            $problems = $this->cloudInitFileProblems($this->tokens(self::ROOT.'/'.$relative), $own);

            $this->assertSame([], $problems['missing'], sprintf(
                "%s builds or rebuilds a machine without handing the adapter exactly new <Request>(..., cloudInit: new CloudInitConfig(...)):\n  %s\n"
                .'The privilege map asks VM.Config.Cloudinit of that capability because the build calls carry cloud-init.',
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
    public function the_pinned_methods_are_every_contract_method_that_takes_a_build_request(): void
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
    public function the_caller_set_finds_a_build_method_named_whole_only_in_the_pinned_handlers(): void
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
        yield 'a callable string in Laravel\'s form, even on the fake' => [$import."app()->call(FakeComputeProvider::class.'@createVirtualMachine', ['request' => \$request]);", false];
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
        yield 'a callable string, in PHP\'s form' => ["app()->call('Lynomia\\Modules\\Compute\\Infrastructure\\Providers\\ProxmoxComputeProvider::createVirtualMachine');", true];
        yield 'a callable string, in Laravel\'s form' => ["app()->call('Lynomia\\Modules\\Compute\\Infrastructure\\Providers\\ProxmoxComputeProvider@createVirtualMachine', ['request' => \$request]);", true];
        yield 'Laravel\'s form, joined to the class' => ["app()->call(ProxmoxComputeProvider::class.'@reinstallVm');", true];
        yield 'Laravel\'s form, in another case' => ["Route::post('/vms', 'ProxmoxComputeProvider@CREATEVIRTUALMACHINE');", true];
        yield 'a definition' => ['public function createVirtualMachine(CreateVmRequest $request): void {}', false];
        yield 'a comment' => ['// $provider->createVirtualMachine($request);', false];
        yield 'another method' => ['$provider->listNodes();', false];
    }

    #[Test]
    #[DataProvider('callerShapes')]
    public function the_caller_set_counts_each_form_of_naming_a_build_method_it_claims(string $code, bool $reaches): void
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
        yield 'a build method in Laravel\'s callable string' => [$good."\napp()->call('Lynomia\\Modules\\Compute\\Infrastructure\\Providers\\ProxmoxComputeProvider@createVirtualMachine', ['request' => \$payload]);", false];
        yield 'the same method in another case, without cloud-init' => [$good."\n\$provider->CreateVirtualMachine(new CreateVmRequest(nodeName: 'b'));", false];
        yield 'the other build method in another case, without cloud-init' => [$good."\n\$provider->reinstallvm('a', 'b', new ReinstallVmRequest(templateReference: 'x'));", false];

        // The argument must be the construction and end with it.
        yield 'a method called on the new request, which hands over what it returns' => ["\$provider->createVirtualMachine(new CreateVmRequest(\n    nodeName: 'a',\n    cloudInit: new CloudInitConfig(sshKeys: []),\n)->withoutCloudInit());", false];
        yield 'a nullsafe call on the new request' => ["\$provider->createVirtualMachine(new CreateVmRequest(nodeName: 'a', cloudInit: new CloudInitConfig(sshKeys: []))?->withoutCloudInit());", false];
        yield 'an operator after the new request' => ["\$provider->createVirtualMachine(new CreateVmRequest(nodeName: 'a', cloudInit: new CloudInitConfig(sshKeys: [])) ?: \$fallback);", false];
        yield 'a method called on the new cloud-init config' => ["\$provider->createVirtualMachine(new CreateVmRequest(\n    nodeName: 'a',\n    cloudInit: new CloudInitConfig(sshKeys: [])->orNothing(),\n));", false];
        yield 'a ternary after the new cloud-init config' => ["\$provider->createVirtualMachine(new CreateVmRequest(nodeName: 'a', cloudInit: new CloudInitConfig(sshKeys: []) ? null : null));", false];
        yield 'the other build method, a method called on its new request' => [$good."\n\$provider->reinstallVm('a', 'b', new ReinstallVmRequest(templateReference: 'x', cloudInit: new CloudInitConfig(sshKeys: []))->withoutCloudInit());", false];
        yield 'cloud-init constructed with no argument list' => ["\$provider->createVirtualMachine(new CreateVmRequest(\n    nodeName: 'a',\n    cloudInit: new CloudInitConfig,\n));", true];
        yield 'a trailing comma after the new request' => ["\$provider->createVirtualMachine(\n    new CreateVmRequest(nodeName: 'a', cloudInit: new CloudInitConfig(sshKeys: [])),\n);", true];
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
     * The files the scans read: each file under the control plane's directory
     * whose name ends `.php`, except what {@see self::NOT_APPLICATION_CODE}
     * names and hidden files and directories. A Blade template's name ends
     * `.php` and it is returned, but what it writes in its own syntax reaches
     * the tokenizer as text; that, and the PHP this walk does not return, are
     * measured in the file header.
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
     * The file's code, without whitespace or comments. A string that
     * interpolates nothing stays one token, so nothing inside it can be read
     * as code. One that interpolates is split into its literal parts and the
     * expressions it interpolates, and those are code: PHP runs
     * `"{$node->storages()->create()}"`, and so they are read as code here.
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
     * The names under which the files the scans read declare a relation able
     * to reach ComputeStorage rows ({@see self::relationsIn()}), read from each
     * of them to a fixed point, so a relation narrowed from, or built through,
     * one declared in another file counts too.
     *
     * @return list<string> {@see self::relationKey()}
     */
    private function storageRelations(): array
    {
        $files = array_map(fn (string $path): array => $this->tokens($path), array_values($this->sourceFiles()));
        $relations = [];

        do {
            $before = count($relations);

            foreach ($files as $tokens) {
                $relations = array_values(array_unique([...$relations, ...$this->relationsIn($tokens, $relations)]));
            }
        } while (count($relations) !== $before);

        return $relations;
    }

    /**
     * The names under which this code declares a relation able to reach
     * ComputeStorage rows. A relation of any kind the grammar derives can
     * create the row it reads: a create on one, belongsTo and the *Through
     * kinds included, is forwarded to the related model's query, and most
     * kinds define creators of their own besides. A method's name counts
     * when, in its body:
     *
     *  - one of the model's relation-declaring methods is called — each
     *    method of the framework's Model whose docblock says it returns a
     *    relation, read off it by reflection
     *    ({@see self::relationGrammar()}): hasOne … morphedByMany and the
     *    newHasOne … newMorphToMany factories they call — or one of the
     *    relation classes they return is constructed with `new` (the
     *    framework's, one the application declares extending one, or either
     *    under an imported alias), with the model, under any name it goes by,
     *    in the argument that carries the related model (`$related`,
     *    `$target` or `$query`, positional or named): `ComputeStorage::class`,
     *    the class named in a string, `ComputeStorage::query()`;
     *  - morphTo, or another of those methods that takes no related model, is
     *    called: it reads the related class off the row at run time, so it
     *    counts whatever it points at;
     *  - a relation already known is returned from `$this`, called or read,
     *    by `return` or an arrow function: one narrowed from another,
     *    `return $this->storages()->where(…)`, or an accessor,
     *    `Attribute::get(fn () => $this->storages)`;
     *  - a has-through is built to a relation already known:
     *    `through(…)->has('storages')`, `throughNodes()->hasStorages()` or
     *    `through(…)->has(fn ($node) => $node->storages())`.
     *
     * An accessor declared as `getPoolsAttribute()` counts under the property
     * it answers. A name registered with `macro(…)` or
     * `resolveRelationUsing(…)` counts when what it is registered with names
     * the model or reaches a relation already known. The relations known are
     * those passed in and those found here, to a fixed point.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  list<string>  $known  {@see self::relationKey()}
     * @return list<string> {@see self::relationKey()}
     */
    private function relationsIn(array $tokens, array $known = []): array
    {
        $names = $this->modelNamesIn($tokens, $this->storageClasses(), $this->storageTable());
        $isModel = $this->modelMatcher($tokens, $names);
        $kinds = $this->relationGrammar()['kinds'];
        $classes = $this->modelNamesIn($tokens, $this->relationClassNames());
        $objectOperators = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];
        $relations = [];

        $mentionsModel = fn (int $at): bool => $isModel($tokens[$at] ?? null)
            || (($tokens[$at][0] ?? null) === T_CONSTANT_ENCAPSED_STRING && $this->classAsValue($tokens, $at, $names) !== null);

        do {
            $before = $relations;
            $all = [...$known, ...array_keys($relations)];
            $method = null;
            $through = null;
            $registered = null;

            foreach ($tokens as $i => $token) {
                $next = $tokens[$i + 1] ?? null;
                $call = $token[0] === T_STRING && ($next[1] ?? null) === '(';
                $key = $this->relationKey($token[1]);
                $onObject = in_array($tokens[$i - 1][0] ?? null, $objectOperators, strict: true);

                if ($token[0] === T_FUNCTION && ($next[0] ?? null) === T_STRING) {
                    $method = $next[1];
                }

                // macro('pools', …) and resolveRelationUsing('pools', …)
                if ($call && in_array($key, ['macro', 'resolverelationusing'], strict: true) && ($tokens[$i + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
                    $registered = ['name' => $this->relationKey(trim($tokens[$i + 2][1], '\'"')), 'close' => $this->closing($tokens, $i + 1)];
                }

                if ($call && $onObject && str_starts_with($key, 'through')) {
                    $through = $method;
                }

                $declares = match (true) {
                    // $this->hasMany(ComputeStorage::class), $this->morphTo()
                    $call && array_key_exists(strtolower($token[1]), $kinds) => $kinds[strtolower($token[1])] === null
                        || $this->argumentMentions($this->argumentFor($tokens, $i + 1, (string) $kinds[strtolower($token[1])]), $mentionsModel),
                    // new HasMany(ComputeStorage::query(), …)
                    $token[0] === T_NEW && $this->namesAny($next, $classes) && ($tokens[$i + 2][1] ?? null) === '(' => $this->argumentMentions($this->argumentFor($tokens, $i + 2, 'query'), $mentionsModel),
                    // return $this->storages()->where(…), fn () => $this->storages
                    $onObject && $token[0] === T_STRING && in_array($key, $all, strict: true)
                        && ($tokens[$i - 2][1] ?? null) === '$this' => $this->isReturned($tokens, $i - 2),
                    // ->through(…)->has('storages'), ->hasStorages()
                    $call && $onObject && $through !== null && $through === $method && str_starts_with($key, 'has') => $this->hasReachesAKnownRelation($tokens, $i, $all),
                    default => false,
                };

                if ($registered !== null && $i < $registered['close'] && ($declares || $mentionsModel($i) || ($onObject && in_array($key, $all, strict: true)))) {
                    $relations[$registered['name']] = true;
                }

                if ($declares && $method !== null) {
                    $relations[$this->relationKey($method)] = true;

                    // getPoolsAttribute() answers ->pools and ->Pools alike.
                    if (preg_match('/^get(\w+)attribute$/i', $method, $accessor) === 1) {
                        $relations[$this->relationKey($accessor[1])] = true;
                    }
                }
            }
        } while ($relations !== $before);

        return array_keys($relations);
    }

    /**
     * Is the expression starting at $i what a `return` or an arrow
     * function's `=>` hands back, parenthesised or not?
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     */
    private function isReturned(array $tokens, int $i): bool
    {
        $before = $i - 1;

        while (($tokens[$before][1] ?? null) === '(') {
            $before--;
        }

        return in_array($tokens[$before][0] ?? null, [T_RETURN, T_DOUBLE_ARROW], strict: true);
    }

    /**
     * A relation's name as this file compares it: lower-cased, as PHP
     * resolves a method name, and without underscores, so that an accessor
     * is matched however its property is spelt (`free_pools`, `freePools`).
     * Two relations that differ only by an underscore are therefore one here,
     * which can only make the scan count more.
     */
    private function relationKey(string $name): string
    {
        return str_replace('_', '', strtolower($name));
    }

    /**
     * The tokens of the argument bound to $parameter in the call whose list
     * opens at $open: the one named for it, or else the first, when that is
     * positional — the parameter that carries a related model is always the
     * first.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @return list<array{0: int|string, 1: string, 2: int, 3?: int}>
     */
    private function argumentFor(array $tokens, int $open, string $parameter): array
    {
        $arguments = $this->arguments($tokens, $open);
        $named = static fn (array $argument): bool => ($argument[0][0] ?? null) === T_STRING && ($argument[1][1] ?? null) === ':';

        foreach ($arguments as $argument) {
            if ($named($argument) && $argument[0][1] === $parameter) {
                return array_slice($argument, 2);
            }
        }

        $first = $arguments[0] ?? [];

        return $named($first) ? [] : $first;
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: int, 3?: int}>  $argument
     * @param  callable(int): bool  $mentionsModel
     */
    private function argumentMentions(array $argument, callable $mentionsModel): bool
    {
        foreach ($argument as $token) {
            if (isset($token[3]) && $mentionsModel($token[3])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the `has…(` at $i, on a pending has-through, reach a relation
     * already known — `has('storages')`, `hasStorages()`, or a closure that
     * calls or reads one?
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  list<string>  $known  {@see self::relationKey()}
     */
    private function hasReachesAKnownRelation(array $tokens, int $i, array $known): bool
    {
        $key = $this->relationKey($tokens[$i][1]);

        if ($key !== 'has') {
            return in_array(substr($key, 3), $known, strict: true);
        }

        $close = $this->closing($tokens, $i + 1);

        for ($j = $i + 2; $j < $close; $j++) {
            $token = $tokens[$j];

            if (($token[0] === T_CONSTANT_ENCAPSED_STRING && in_array($this->relationKey(trim($token[1], '\'"')), $known, strict: true))
                || ($token[0] === T_STRING
                    && in_array($this->relationKey($token[1]), $known, strict: true)
                    && in_array($tokens[$j - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], strict: true))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * How the framework's model declares a relation, read off it by
     * reflection rather than listed: every method of the model documented to
     * return a relation, and the relation classes those return.
     *
     * `kinds` maps each such method to the parameter that carries the
     * related model — its class (`$related`, `$target`) or its query
     * (`$query`) — or to null when it takes none: morphTo reads the related
     * class off the row at run time.
     *
     * @return array{kinds: array<string, string|null>, classes: list<string>}
     */
    private function relationGrammar(): array
    {
        if (self::$relationGrammar !== null) {
            return self::$relationGrammar;
        }

        $kinds = [];
        $classes = [];

        foreach ((new ReflectionClass(Model::class))->getMethods() as $method) {
            if (preg_match('/@return\s+\\\\?([\w\\\\]+)/', (string) $method->getDocComment(), $match) !== 1 || ! is_a($match[1], Relation::class, allow_string: true)) {
                continue;
            }

            $first = $method->getParameters()[0] ?? null;
            $kinds[strtolower($method->getName())] = $first !== null && in_array($first->getName(), ['related', 'target', 'query'], strict: true) ? $first->getName() : null;
            $classes[] = $match[1];
        }

        // The derivation must at least find the kinds this file names.
        foreach (['hasone', 'hasmany', 'morphone', 'morphmany', 'belongsto', 'belongstomany', 'morphtomany', 'morphedbymany', 'hasonethrough', 'hasmanythrough', 'newhasmany'] as $expected) {
            $this->assertNotNull($kinds[$expected] ?? null, 'The relation kinds were not derived, so the relation half of the writer scan proves nothing.');
        }

        $this->assertArrayHasKey('morphto', $kinds);
        $this->assertNull($kinds['morphto']);

        return self::$relationGrammar = ['kinds' => $kinds, 'classes' => array_values(array_unique($classes))];
    }

    /**
     * The relation classes the model's declaring methods return, and every
     * class the application declares extending one, to a fixed point.
     *
     * @return list<string> lower-cased short names
     */
    private function relationClassNames(): array
    {
        return self::$relationClassNames ??= $this->classesDerivedFrom(array_values(array_unique(array_map(
            static fn (string $class): string => strtolower(class_basename($class)),
            $this->relationGrammar()['classes'],
        ))));
    }

    /**
     * The class names this scan takes to be the model, derived from the files
     * {@see self::sourceFiles()} returns rather than listed: ComputeStorage
     * itself; a named class declared extending one of these (a subclass
     * writes the same table); a named class whose body gives `$table` the
     * model's table as a literal string (a second model on the same table,
     * this repository's own idiom for naming a table); and a factory whose
     * `$model` is one of these — to a fixed point, so a subclass of a subclass
     * is found too. A class that reaches the table any other way is not one of
     * these names; see WHAT IT DOES NOT COUNT.
     *
     * @return list<string> lower-cased short names
     */
    private function storageClasses(): array
    {
        return self::$storageClasses ??= $this->classesDerivedFrom(['computestorage'], $this->storageTable());
    }

    /**
     * The model's table, as the model itself answers it.
     */
    private function storageTable(): string
    {
        return (new ComputeStorage)->getTable();
    }

    /**
     * These classes, and every class the files declare extending one of them,
     * naming one as a factory's `$model` or — when a table is given — giving
     * `$table` that table, to a fixed point.
     *
     * @param  list<string>  $seed  lower-cased short names
     * @return list<string> lower-cased short names
     */
    private function classesDerivedFrom(array $seed, ?string $table = null): array
    {
        $files = array_map(fn (string $path): array => $this->tokens($path), array_values($this->sourceFiles()));
        $classes = $seed;

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

                $classes = array_values(array_unique([...$classes, ...array_intersect($this->modelNamesIn($tokens, $classes, $table), $declared)]));
            }
        } while ($classes !== $before);

        return $classes;
    }

    /**
     * The names the model goes by in this code: the ones given, every named
     * class this code declares extending one of them, declaring one as a
     * factory's `$model` or — when a table is given — giving `$table` that
     * table in its body, and every alias it imports one of them under with
     * `use … as`.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  list<string>  $names  lower-cased short names
     * @return list<string>
     */
    private function modelNamesIn(array $tokens, array $names, ?string $table = null): array
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

                    // protected $table = 'compute_storages';
                    if ($table !== null && $this->bodyNamesTable($tokens, $i + 2, $table)) {
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
     * Does this token name the model: one of these names, or — inside a file
     * that declares the model, a subclass or its factory — `self`, `static`
     * or `parent`?
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  list<string>  $names  lower-cased short names
     * @return callable(array{0: int|string, 1: string, 2: int, 3?: int}|null): bool
     */
    private function modelMatcher(array $tokens, array $names): callable
    {
        $declaresModel = false;

        foreach ($tokens as $i => $token) {
            if ($token[0] === T_CLASS && ($tokens[$i + 1][0] ?? null) === T_STRING && in_array(strtolower($tokens[$i + 1][1]), $names, strict: true)) {
                $declaresModel = true;
            }
        }

        return fn (?array $token): bool => $this->namesAny($token, $names)
            || ($declaresModel && $token !== null && ($token[0] === T_STATIC || ($token[0] === T_STRING && in_array(strtolower($token[1]), ['self', 'parent'], strict: true))));
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  list<string>  $relations  {@see self::relationKey()}
     */
    private function createsStorage(array $tokens, array $relations): bool
    {
        $table = $this->storageTable();
        $names = $this->modelNamesIn($tokens, $this->storageClasses(), $table);
        $count = count($tokens);
        $isModel = $this->modelMatcher($tokens, $names);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // new ComputeStorage, and an anonymous class extending it or
            // giving `$table` its table
            if ($token[0] === T_NEW && ($isModel($tokens[$i + 1] ?? null) || $this->isAnonymousSubclass($tokens, $i + 1, $isModel, $table))) {
                return true;
            }

            // SQL in a string that puts rows into the table, and
            // 'ComputeStorage::create' or 'ComputeStorage@create' as a
            // callable string.
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

            // DB::table('compute_storages')… or ->from('compute_storages')…,
            // aliased or schema-qualified or not
            if ($token[0] === T_STRING && in_array(strtolower($token[1]), ['table', 'from'], strict: true)
                && ($tokens[$i + 1][1] ?? null) === '('
                && ($tokens[$i + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
                && preg_match('/^(\w+\.)?'.preg_quote($table, '/').'(\s+as\s+\w+)?$/i', trim($tokens[$i + 2][1], '\'"')) === 1
            ) {
                $chainStart = $i + 1;
            }

            // ->storages()…, ->storages->… or ->storages[0]…, and the same
            // relation named in a literal, ->{'storages'}()…
            if (in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], strict: true)) {
                $literal = ($tokens[$i + 1][1] ?? null) === '{'
                    && ($tokens[$i + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
                    && ($tokens[$i + 3][1] ?? null) === '}';
                $name = $literal ? trim($tokens[$i + 2][1], '\'"') : (($tokens[$i + 1][0] ?? null) === T_STRING ? $tokens[$i + 1][1] : null);
                $after = $literal ? $i + 4 : $i + 2;

                if ($name !== null
                    && in_array($this->relationKey($name), $relations, strict: true)
                    && in_array($tokens[$after][1] ?? null, ['(', '->', '?->', '['], strict: true)
                ) {
                    $chainStart = $after;
                }
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
     * Is the code after `new` at $i `class(…) extends <the model>`, or an
     * anonymous class whose body gives `$table` the model's table?
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  callable(array{0: int|string, 1: string, 2: int}|null): bool  $isModel
     */
    private function isAnonymousSubclass(array $tokens, int $i, callable $isModel, string $table): bool
    {
        if (($tokens[$i][0] ?? null) !== T_CLASS) {
            return false;
        }

        $next = $i + 1;

        if (($tokens[$next][1] ?? null) === '(') {
            $next = $this->closing($tokens, $next) + 1;
        }

        return (($tokens[$next][0] ?? null) === T_EXTENDS && $isModel($tokens[$next + 1] ?? null))
            || $this->bodyNamesTable($tokens, $next, $table);
    }

    /**
     * Does the class body that opens at the first `{` from $from give
     * `$table` this table as a literal string — bare or schema-qualified, in
     * any case? Read anywhere in the body, so a local variable of that name
     * counts too, which can only make the scan count more.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     */
    private function bodyNamesTable(array $tokens, int $from, string $table): bool
    {
        $count = count($tokens);
        $open = $from;

        while ($open < $count && $tokens[$open][1] !== '{') {
            $open++;
        }

        $depth = 0;

        for ($j = $open; $j < $count; $j++) {
            if ($tokens[$j][1] === '{' || in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], strict: true)) {
                $depth++;
            } elseif ($tokens[$j][1] === '}' && --$depth === 0) {
                return false;
            }

            if ($tokens[$j][0] === T_VARIABLE && $tokens[$j][1] === '$table'
                && ($tokens[$j + 1][1] ?? null) === '='
                && ($tokens[$j + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
                && preg_match('/^(\w+\.)?'.preg_quote($table, '/').'$/i', trim($tokens[$j + 2][1], '\'"')) === 1
            ) {
                return true;
            }
        }

        return false;
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
     * The methods of the framework's builders, relations (every class the
     * model's relation-declaring methods return), model and factories whose
     * names have the shape of one that makes a row, or an instance that
     * saving makes into one — read off those classes by reflection rather
     * than listed, so a framework upgrade that adds one under a name of these
     * shapes is counted without anybody remembering to. A creator named
     * otherwise is not; the shapes are the list.
     *
     * @return list<string> lower-cased, as PHP resolves a method name
     */
    private function creatorMethods(): array
    {
        if ($this->creatorMethods !== null) {
            return $this->creatorMethods;
        }

        $creators = [];

        foreach ([EloquentBuilder::class, QueryBuilder::class, Model::class, Factory::class, ...$this->relationGrammar()['classes']] as $class) {
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
     * Is this string `'…ComputeStorage::create'` or `'…ComputeStorage@create'`
     * — a callable string naming a creator on the model, in PHP's form or
     * the one Laravel's container calls?
     *
     * @param  list<string>  $names  lower-cased short names
     */
    private function isStaticCreatorString(string $literal, array $names): bool
    {
        return preg_match('/(?:^|\\\\)(\w+)(?:::|@)(\w+)$/', trim($literal, '\'"'), $match) === 1
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
     * literal, `->{'create'}(`, is that method. The chain runs on through
     * what a call returns however it is reached: `->` and `?->`, a static
     * call on it (`::`), an index (`get()[0]`) and a property read that is
     * followed by more chain (`->map->`, `->items[0]`).
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @return array{names: list<string>, end: int}
     */
    private function chain(array $tokens, int $i): array
    {
        $names = [];
        $count = count($tokens);
        $accessors = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

        while ($i < $count) {
            $token = $tokens[$i];

            if ($token[1] === '(') {
                $i = $this->closing($tokens, $i) + 1;

                continue;
            }

            if ($token[1] === '[') {
                $i = $this->closing($tokens, $i, '[', ']') + 1;

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

            $literal = $token[1] === '{'
                && ($tokens[$i + 1][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
                && ($tokens[$i + 2][1] ?? null) === '}';

            if ($literal && ($tokens[$i + 3][1] ?? null) === '(') {
                $names[] = trim($tokens[$i + 1][1], '\'"');
                $i += 3;

                continue;
            }

            // A property read the chain goes on through: `->map->`,
            // `->items[0]`, `->node::`, `->{'map'}->`.
            $goesOn = static fn (?array $after): bool => in_array($after[0] ?? null, $accessors, strict: true) || ($after[1] ?? null) === '[';

            if (in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], strict: true)
                && (($named && $goesOn($tokens[$i + 1] ?? null)) || ($literal && $goesOn($tokens[$i + 3] ?? null)))
            ) {
                $i += $named ? 1 : 3;

                continue;
            }

            if (in_array($token[0], $accessors, strict: true)) {
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
     * Every build method, checked in one file: each call to any of them that
     * is written whole must hand the adapter exactly its own request carrying
     * exactly a cloud-init config ({@see self::carriesCloudInit()}); no member
     * may be reached through a variable or a braced name (`->$name`,
     * `::$name`, `->{…}`), no call_user_func may appear, and no build method
     * may be named in a string the way a callable names one
     * ({@see self::stringNames()}); and the file's own method must be called
     * by name at least once — the backstop.
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
     * array, `call_user_func`, `->{'…'}(`, `'Class::method'` or Laravel's
     * `'Class@method'` name one? The same two readings the pin makes inside
     * the pinned files, made of each file the scans read.
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
     * Is this token a string whose content is the method's name, in any case,
     * the way a callable names one: alone (a callable array's method,
     * `->{'…'}(`), as `Class::name` (PHP's callable string) or as
     * `Class@name` (the one Laravel's container, routes and listeners call)?
     * A class joined on at run time, `X::class.'@name'`, leaves the name
     * whole in its own string, so it is read too.
     *
     * @param  array{0: int|string, 1: string, 2: int}  $token
     */
    private function stringNames(array $token, string $method): bool
    {
        return in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], strict: true)
            && preg_match('/(^|::|@)'.preg_quote($method, '/').'$/i', trim($token[1], " \t\n\r\0\x0B'\"")) === 1;
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
     * Does the call whose argument list opens at $open have a positional
     * argument that is exactly `new $request(…)`, among whose own top-level
     * arguments is `cloudInit:` bound to exactly `new CloudInitConfig(…)` (or
     * `new CloudInitConfig` with no list)?
     *
     * "Exactly" is read off the tokens: the argument ends at the parenthesis
     * that closes the construction. PHP 8.4 lets a method be called on a
     * `new` expression without wrapping it, so `new $request(…)->without()`
     * hands the adapter whatever `without()` returns, and an operator after
     * the construction (`?:`, `??`, a ternary) hands over what it evaluates
     * to; neither is the construction, and neither passes.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     */
    private function carriesCloudInit(array $tokens, int $open, string $request): bool
    {
        foreach ($this->arguments($tokens, $open) as $argument) {
            if (($argument[2][1] ?? null) !== '(' || ! $this->isExactlyConstruction($tokens, $argument, $request)) {
                continue;
            }

            $offset = $this->offsetOf($tokens, $argument[2]);

            foreach ($this->arguments($tokens, $offset) as $inner) {
                if (($inner[0][1] ?? null) === 'cloudInit'
                    && ($inner[1][1] ?? null) === ':'
                    && $this->isExactlyConstruction($tokens, array_slice($inner, 2), 'CloudInitConfig')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Is this argument `new $class`, or `new $class(…)` and nothing after the
     * parenthesis that closes it?
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  list<array{0: int|string, 1: string, 2: int, 3?: int}>  $argument
     */
    private function isExactlyConstruction(array $tokens, array $argument, string $class): bool
    {
        if (($argument[0][0] ?? null) !== T_NEW || ! $this->names($argument[1] ?? null, $class)) {
            return false;
        }

        if (count($argument) === 2) {
            return true;
        }

        if (($argument[2][1] ?? null) !== '(') {
            return false;
        }

        $last = $argument[array_key_last($argument)];

        return $this->offsetOf($tokens, $last) === $this->closing($tokens, $this->offsetOf($tokens, $argument[2]));
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
     * Where the bracket that opens at $open closes.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     */
    private function closing(array $tokens, int $open, string $opening = '(', string $closing = ')'): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i][1] === $opening) {
                $depth++;
            } elseif ($tokens[$i][1] === $closing) {
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
