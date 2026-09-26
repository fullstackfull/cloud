<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Infrastructure\Application\Preflight\Checks\MappingChain;
use Lynomia\Modules\Infrastructure\Application\Preflight\Checks\ProviderChain;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckStatus;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A check that is not in the report must never read as a check that passed.
 *
 * ===========================================================================
 * THE DEFECT THIS FILE EXISTS FOR
 * ===========================================================================
 *
 * The compute mapping chain asked about addresses only below the template
 * check's early return, so it asked only when no template was installable. On
 * an estate with an installable template and no address pool at all,
 * `infra:preflight --product=vps` reported five mapping checks, all `pass`,
 * and no sixth line at any status. Nothing was red because the one question
 * whose answer was "no" had not been asked, and the report gave no sign that
 * a question was missing.
 *
 * That is the shape to guard against, not the one instance: a check can only
 * be absent silently from a band that does not block. In a band that blocks,
 * the operator is already sent to act on the first failure, and the chain
 * stopping there is the root-cause rule. In a band that does not block, an
 * absent check is a green nobody earned.
 *
 * ===========================================================================
 * THE RULE, AND WHERE IT RUNS
 * ===========================================================================
 *
 * For every estate shape below and every product that shape names, the
 * `mapping.*` band read from the command's own output must:
 *
 *   - hold no check that {@see self::MAPPING_CHECKS} does not list for that
 *     product;
 *   - hold no check twice;
 *   - and, when nothing in it blocks, hold every check the constant lists.
 *
 * Read by id rather than by category, because the dedicated chain's two
 * checks are reported in the hardware category and a band selected by
 * category would never see them.
 *
 * The constant is not self-checking, and the qualification matters: a check
 * somebody adds to a chain without adding it here is named by the first
 * assertion only for a product that some shape below names, and only on a
 * shape that reaches the new check. The prepared arm serves six products and
 * one shape names one of them, DNS. That arm reads nothing, so the shape
 * needs no fixture — which is exactly why it was easy to leave out, and
 * without it, dropping `mapping.none` would leave every test here green. The
 * other five products on that arm are named by no shape.
 *
 * The rule is also checked for being live: every product a shape names must
 * reach a band that does not block in at least one shape, or the third
 * assertion would never have run for it and would prove nothing.
 *
 * ===========================================================================
 * THE INVENTORY: EVERY PLACE A CHECK CAN BE ABSENT, AND WHAT SAYS SO
 * ===========================================================================
 *
 * The good idiom is {@see ProviderChain}'s: a chain that stops records every
 * rung below the break as `not_tested`, naming the rung that stopped it. What
 * each scope of `infra:preflight` emits, and every place a check it could
 * emit is left out:
 *
 *   estate    provider.* for each provider row (provider.none, blocked, when
 *             there is none), dependency.*, the naming audit's findings, and
 *             readiness.* for the five families in service. No mapping.* —
 *             the mapping band runs under --product only.
 *   site      site.exists (fail) or site.machines (blocked) and nothing else
 *             when the datacenter is unknown or holds no machine; otherwise
 *             site.machines and provider.* for the rows bound to its machines.
 *   provider  provider.exists (fail) for an unknown row; otherwise provider.*.
 *   product   product.exists (fail) for an unknown product; otherwise
 *             mapping.*, provider.* for the rows in the categories that
 *             product requires, dependency.* and readiness.*.
 *   machine   machine.exists (fail) for an unknown machine; otherwise
 *             machine.registered and machine.classification, then either
 *             machine.bmc (blocked) and nothing more when no controller is
 *             bound, or provider.* through the machine's controller.
 *
 * Inside the chains:
 *
 *   - {@see ProviderChain::inspect()}: every return before the last spreads
 *     `notTestedBelow()`, including the one for a row whose driver this build
 *     has no adapter for, which used to return its failure alone.
 *     `provider.machine` is asked only of a driver that needs an endpoint in a
 *     category that runs on a machine of ours; for any other row the question
 *     does not exist.
 *   - {@see MappingChain}: the compute chain stops after `mapping.cluster`
 *     when no cluster accepts placement, and the dedicated chain after
 *     `mapping.machine` when no machine is registered. Both of those findings
 *     block. On every other path each chain emits every check it has;
 *     capacity with no eligible node is recorded `not_tested`, and the address
 *     check is asked whatever the template check found.
 *   - `DependencyChain::backups()` returns without
 *     `dependency.backup_verification` when collecting the metrics registry
 *     throws, having recorded `dependency.backup_metrics` as `not_tested`.
 *     It is the one exit in this list that can leave a check out of a band
 *     that does not block without saying which: `monitoring()` collects the
 *     registry again and reports `dependency.monitoring` as a failure only
 *     if that throws too. The registry catches each collector's own throw,
 *     so neither is easy to reach. Recorded, not changed here.
 *   - The service's own wrappers: a target whose checks throw becomes one
 *     `preflight.check_failed` (fail), and a provider reached after the
 *     deadline becomes one `preflight.deadline` (`not_tested`) naming the
 *     budget, in place of its chain.
 *
 * ===========================================================================
 * WHAT THE ADDRESS CHECK STILL DOES NOT ASK
 * ===========================================================================
 *
 * It asks whether an active pool exists. The allocator asks more, and the
 * terms it asks and the check does not are set out beside the check, in
 * `MappingChain::addressFinding()`. The green compute shape here is built so
 * that it can really give out an address — a public pool with a seeded IPv4
 * subnet — so that narrowing the check to ask more would leave it green.
 */
final class APreflightNeverDropsACheckSilentlyTest extends TestCase
{
    use RefreshDatabase;

    private const array COMPUTE = [
        'mapping.cluster',
        'mapping.nodes',
        'mapping.storage',
        'mapping.capacity',
        'mapping.template',
        'mapping.network',
    ];

    private const array HOSTING = [
        'mapping.hosting_node',
        'mapping.hosting_package',
        'mapping.hosting_node_install',
    ];

    private const array PREPARED = ['mapping.none'];

    /**
     * Every check id the mapping chain can emit, per product.
     *
     * @var array<string, list<string>>
     */
    private const array MAPPING_CHECKS = [
        'vps' => self::COMPUTE,
        'gpu_compute' => self::COMPUTE,
        'dedicated' => ['mapping.machine', 'mapping.bmc'],
        'shared_hosting' => self::HOSTING,
        'wordpress' => self::HOSTING,
        'domains' => ['mapping.tld'],
        'dns' => self::PREPARED,
        'backups' => self::PREPARED,
        'cdn' => self::PREPARED,
        'object_storage' => self::PREPARED,
        'email_hosting' => self::PREPARED,
        'managed_kubernetes' => self::PREPARED,
    ];

    /**
     * The estates the rule runs on, and the products each one names.
     *
     * @var array<string, list<string>>
     */
    private const array SHAPES = [
        'nothing is registered' => ['vps', 'gpu_compute', 'dedicated', 'shared_hosting', 'wordpress', 'domains'],
        'a compute estate with an installable template and no address pool' => ['vps', 'gpu_compute'],
        'a compute estate whose only address pool is switched off' => ['vps', 'gpu_compute'],
        'a compute estate with an active address pool and no installable template' => ['vps', 'gpu_compute'],
        'a compute estate that can build a machine and give it an address' => ['vps', 'gpu_compute'],
        'a machine cleared for reimaging with its controller bound' => ['dedicated'],
        'an active hosting node and a mapped package' => ['shared_hosting', 'wordpress'],
        'a catalogued TLD that is enabled and open to registration' => ['domains'],
        'the prepared arm, which reads nothing' => ['dns'],
    ];

    /* =====================================================================
     | The defect, and its twin
     ===================================================================== */

    #[Test]
    public function an_installable_template_and_no_address_pool_is_not_reported_green(): void
    {
        $this->build('a compute estate with an installable template and no address pool');

        $network = $this->check($this->mappingBand('vps'), 'mapping.network');

        $this->assertSame('fail', $network['status']);
        $this->assertStringContainsString('No address pool is registered', $network['summary']);
        $this->assertStringContainsString('Register an address pool', (string) $network['next_action']);
    }

    #[Test]
    public function an_address_pool_that_is_switched_off_is_not_counted_as_one(): void
    {
        /*
         * Counting pool rows was the defect one level down. `is_active` on a
         * pool is the allocator's kill switch, and a pool that is switched
         * off gives out no address from either of its branches — so an estate
         * whose only pool is off has nothing to give a machine.
         */
        $this->build('a compute estate whose only address pool is switched off');

        $network = $this->check($this->mappingBand('vps'), 'mapping.network');

        $this->assertSame('fail', $network['status']);
        $this->assertStringContainsString('1 address pool(s) are registered and none of them is active', $network['summary']);
    }

    #[Test]
    public function an_active_address_pool_passes_whatever_the_template_check_found(): void
    {
        DB::beginTransaction();

        try {
            $this->build('a compute estate that can build a machine and give it an address');

            $band = $this->mappingBand('vps');

            $this->assertSame('pass', $this->check($band, 'mapping.template')['status']);
            $this->assertSame('pass', $this->check($band, 'mapping.network')['status']);
        } finally {
            DB::rollBack();
        }

        $this->build('a compute estate with an active address pool and no installable template');

        $band = $this->mappingBand('vps');

        $this->assertSame('fail', $this->check($band, 'mapping.template')['status']);
        $this->assertSame('pass', $this->check($band, 'mapping.network')['status']);
    }

    /* =====================================================================
     | The rule
     ===================================================================== */

    #[Test]
    public function a_band_that_does_not_block_holds_every_check_its_chain_can_emit(): void
    {
        $ruled = [];

        foreach (self::SHAPES as $shape => $products) {
            /*
             * One savepoint per shape, rolled back before the next, so no
             * shape inherits another's rows. Cheaper and more complete than
             * deleting tables by name, which only clears the tables somebody
             * remembered to list.
             */
            DB::beginTransaction();

            try {
                $this->build($shape);

                foreach ($products as $product) {
                    $band = $this->mappingBand($product);
                    $ids = array_column($band, 'id');
                    $expected = self::MAPPING_CHECKS[$product];

                    $this->assertSame(
                        array_values(array_unique($ids)),
                        $ids,
                        sprintf('%s, --product=%s: a check was reported twice.', $shape, $product),
                    );

                    $this->assertSame(
                        [],
                        array_values(array_diff($ids, $expected)),
                        sprintf('%s, --product=%s: the chain emitted a check MAPPING_CHECKS does not list. Add it there.', $shape, $product),
                    );

                    if ($this->blocks($band)) {
                        continue;
                    }

                    $ruled[$product] = true;

                    $this->assertSame(
                        [],
                        array_values(array_diff($expected, $ids)),
                        sprintf(
                            '%s, --product=%s: nothing in this band blocks, and it does not hold %s. A check absent from a band that does not block has been dropped silently. The band: %s.',
                            $shape,
                            $product,
                            implode(', ', array_diff($expected, $ids)),
                            json_encode($band, JSON_THROW_ON_ERROR),
                        ),
                    );
                }
            } finally {
                DB::rollBack();
            }
        }

        $named = array_values(array_unique(array_merge(...array_values(self::SHAPES))));
        $reached = array_keys($ruled);

        sort($named);
        sort($reached);

        $this->assertSame(
            $named,
            $reached,
            'The rule never met a band that does not block for: '.implode(', ', array_diff($named, $reached)).'. For those products it proved nothing.',
        );
    }

    #[Test]
    public function the_constant_lists_every_product(): void
    {
        /*
         * A failure rather than the undefined-array-key error a product
         * missing from the constant would otherwise raise inside the rule.
         */
        $products = array_column(Product::cases(), 'value');
        $listed = array_keys(self::MAPPING_CHECKS);

        sort($products);
        sort($listed);

        $this->assertSame($products, $listed);
    }

    /* =====================================================================
     | The provider chain's one bare return
     ===================================================================== */

    #[Test]
    public function a_row_whose_driver_has_no_adapter_names_every_rung_it_did_not_reach(): void
    {
        $provider = ProviderInstance::factory()->create(['driver' => 'a-driver-this-build-has-never-heard-of']);

        Artisan::call('infra:preflight', ['--mode' => 'simulation', '--provider' => $provider->name, '--json' => true]);

        /** @var array{checks: list<array<string, mixed>>} $report */
        $report = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);

        $statuses = [];

        foreach ($report['checks'] as $check) {
            $statuses[(string) $check['id']] = (string) $check['status'];

            if ($check['status'] === 'not_tested') {
                $this->assertStringContainsString('provider.configuration', (string) $check['summary']);
            }
        }

        $this->assertSame([
            'provider.configuration' => 'fail',
            'provider.credential' => 'not_tested',
            'provider.endpoint' => 'not_tested',
            'provider.identity' => 'not_tested',
            'provider.capabilities' => 'not_tested',
            'provider.licence' => 'not_tested',
        ], $statuses);
    }

    /* =====================================================================
     | Helpers
     ===================================================================== */

    /**
     * The mapping band of one product preflight, as the command prints it.
     *
     * @return list<array{id: string, status: string, summary: string, next_action: ?string}>
     */
    private function mappingBand(string $product): array
    {
        Artisan::call('infra:preflight', ['--mode' => 'simulation', '--product' => $product, '--json' => true]);

        /** @var array{checks: list<array<string, mixed>>} $report */
        $report = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);

        $band = [];

        foreach ($report['checks'] as $check) {
            if (! str_starts_with((string) $check['id'], 'mapping.')) {
                continue;
            }

            $band[] = [
                'id' => (string) $check['id'],
                'status' => (string) $check['status'],
                'summary' => (string) $check['summary'],
                'next_action' => $check['next_action'] === null ? null : (string) $check['next_action'],
            ];
        }

        return $band;
    }

    /**
     * @param  list<array{id: string, status: string, summary: string, next_action: ?string}>  $band
     */
    private function blocks(array $band): bool
    {
        foreach ($band as $check) {
            if (CheckStatus::from($check['status'])->blocking()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{id: string, status: string, summary: string, next_action: ?string}>  $band
     * @return array{id: string, status: string, summary: string, next_action: ?string}
     */
    private function check(array $band, string $id): array
    {
        foreach ($band as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }

        $this->fail(sprintf(
            '%s is not in the mapping band at any status. The band holds: %s.',
            $id,
            implode(', ', array_map(static fn (array $check): string => $check['id'].' '.$check['status'], $band)),
        ));
    }

    private function build(string $shape): void
    {
        match ($shape) {
            'nothing is registered',
            'the prepared arm, which reads nothing' => null,
            'a compute estate with an installable template and no address pool' => $this->computeEstate(template: true),
            'a compute estate whose only address pool is switched off' => $this->computeEstate(template: true, pool: 'inactive'),
            'a compute estate with an active address pool and no installable template' => $this->computeEstate(template: false, pool: 'allocatable'),
            'a compute estate that can build a machine and give it an address' => $this->computeEstate(template: true, pool: 'allocatable'),
            'a machine cleared for reimaging with its controller bound' => ManagedServer::factory()->clearedForReimage()->withBmc()->create(),
            'an active hosting node and a mapped package' => [HostingNode::factory()->create(), HostingPackage::factory()->create()],
            'a catalogued TLD that is enabled and open to registration' => DomainTld::factory()->onSale()->create(),
        };
    }

    /**
     * A cluster that takes placement, a healthy reconciled node, active
     * storage, a template with or without the reference the hypervisor knows
     * it by, and one of three address arrangements.
     *
     * @param  'none'|'inactive'|'allocatable'  $pool
     */
    private function computeEstate(bool $template, string $pool = 'none'): void
    {
        $cluster = ComputeCluster::factory()->create(['status' => 'active']);

        ComputeNode::factory()->create([
            'cluster_id' => $cluster->getKey(),
            'status' => 'active',
            'is_healthy' => true,
            'last_seen_at' => now(),
        ]);

        ComputeStorage::factory()->create(['cluster_id' => $cluster->getKey(), 'is_active' => true]);

        VmTemplate::factory()->create([
            'cluster_id' => $cluster->getKey(),
            'provider_reference' => $template ? 'local:vztmpl/ubuntu-24.04' : null,
        ]);

        if ($pool === 'inactive') {
            IpPool::factory()->inactive()->create();
        }

        if ($pool === 'allocatable') {
            $subnet = Subnet::factory()->forBlock('198.51.100.0/29')->create([
                'ip_pool_id' => IpPool::factory()->create()->getKey(),
            ]);

            app(SeedSubnetAddresses::class)->execute($subnet);
        }
    }
}
