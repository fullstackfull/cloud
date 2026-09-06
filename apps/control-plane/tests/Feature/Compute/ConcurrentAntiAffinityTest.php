<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\Enums\PlacementRejectionReason;
use Lynomia\Modules\Compute\Domain\Exceptions\NodeCapacityExceededException;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Compute\Concerns\CreatesComputeFleet;
use Tests\TestCase;

/**
 * One customer, two orders placed in the same second, one node with plenty of
 * room for both.
 *
 * Capacity is not what is being defended here — the node could take ten of
 * these machines. Anti-affinity is, and it is the constraint that a
 * before-the-lock check cannot enforce on its own: the scheduler excludes a
 * node the customer already occupies, but it reads machines that have already
 * been written, and two workers scoring at the same moment both see an empty
 * node and both choose it. The customer who paid for two machines in different
 * failure domains gets two machines behind one power supply, and finds out
 * when the node dies.
 *
 * The re-check belongs under the node's row lock for exactly the reason the
 * capacity one does, and this test proves it holds across two genuinely
 * separate connections. RefreshDatabase is deliberately not used: it wraps the
 * test in a single transaction on a single connection, where the second worker
 * could not see the first's fixtures and the two could never race in the first
 * place.
 */
final class ConcurrentAntiAffinityTest extends TestCase
{
    use CreatesComputeFleet;

    private const string SECOND_CONNECTION = 'worker_b';

    private ComputeNode $node;

    private Customer $customer;

    private string $defaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultConnection = (string) config('database.default');
        $this->wipe();

        config([
            'database.connections.'.self::SECOND_CONNECTION => config(
                'database.connections.'.$this->defaultConnection,
            ),
        ]);

        // Worker B genuinely blocks on worker A's row lock, and A is frozen
        // inside a single-threaded test, so a short timeout turns what would
        // be a hang into an assertable failure.
        DB::connection(self::SECOND_CONNECTION)->statement("SET lock_timeout = '3s'");

        config()->set('compute.scheduler.capacity_threshold_percent', 100);

        // Room for many machines: this test must fail for anti-affinity
        // reasons or not at all.
        $this->node = ComputeNode::factory()->for($this->cluster(), 'cluster')->create([
            'memory_mib' => 262144,
            'memory_headroom_percent' => 0,
            'cpu_cores' => 64,
            'storage_gib' => 8192,
        ]);

        $this->customer = Customer::factory()->create();
    }

    protected function tearDown(): void
    {
        while (DB::connection($this->defaultConnection)->transactionLevel() > 0) {
            DB::connection($this->defaultConnection)->rollBack();
        }

        DB::purge(self::SECOND_CONNECTION);
        $this->wipe();

        parent::tearDown();
    }

    #[Test]
    public function a_customers_two_simultaneous_orders_cannot_both_land_on_one_node(): void
    {
        $machine = new VmResources(vcpu: 2, memoryMib: 4096, diskGib: 50);

        /*
         * Worker A does what provisioning does: reserves the node and writes
         * the machine's row in the same transaction. Both are invisible to
         * anybody else, and the node's row is locked, until it commits.
         */
        DB::connection($this->defaultConnection)->beginTransaction();

        app(ReserveNodeCapacity::class)->execute(
            $this->node,
            $machine,
            customerId: (string) $this->customer->getKey(),
            antiAffinityLimit: 1,
        );

        $this->recordMachine();

        try {
            $this->asWorkerB(fn (): ComputeNode => app(ReserveNodeCapacity::class)->execute(
                $this->node,
                $machine,
                customerId: (string) $this->customer->getKey(),
                antiAffinityLimit: 1,
            ));

            $this->fail('The second order was committed while the first was still holding the node.');
        } catch (QueryException $e) {
            // B waited rather than reading around A, which is the mechanism.
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        }

        DB::connection($this->defaultConnection)->commit();

        try {
            $this->asWorkerB(fn (): ComputeNode => app(ReserveNodeCapacity::class)->execute(
                $this->node,
                $machine,
                customerId: (string) $this->customer->getKey(),
                antiAffinityLimit: 1,
            ));

            $this->fail('Both machines for one customer were placed on one node; their redundancy is worthless.');
        } catch (NodeCapacityExceededException $e) {
            $this->assertSame(
                PlacementRejectionReason::AntiAffinity->value,
                $e->context()['reason'],
                'The node had room, so the refusal must be about the customer, not about capacity.',
            );
        }

        // Exactly one order landed here. The second is free to be re-placed.
        $this->assertSame(1, $this->freshNode()->vm_count);
    }

    #[Test]
    public function another_customers_order_is_untouched_by_the_rule(): void
    {
        $machine = new VmResources(vcpu: 2, memoryMib: 4096, diskGib: 50);

        app(ReserveNodeCapacity::class)->execute(
            $this->node,
            $machine,
            customerId: (string) $this->customer->getKey(),
            antiAffinityLimit: 1,
        );

        $this->recordMachine();

        // Anti-affinity is per customer. Refusing everybody else's order
        // because one customer is here would empty the fleet.
        $this->asWorkerB(fn (): ComputeNode => app(ReserveNodeCapacity::class)->execute(
            $this->node,
            $machine,
            customerId: (string) Customer::factory()->create()->getKey(),
            antiAffinityLimit: 1,
        ));

        $this->assertSame(2, $this->freshNode()->vm_count);
    }

    #[Test]
    public function a_placement_the_scheduler_waived_the_rule_for_is_not_refused_here(): void
    {
        $machine = new VmResources(vcpu: 2, memoryMib: 4096, diskGib: 50);

        app(ReserveNodeCapacity::class)->execute(
            $this->node,
            $machine,
            customerId: (string) $this->customer->getKey(),
            antiAffinityLimit: 1,
        );

        $this->recordMachine();

        /*
         * A null limit is the scheduler saying it deliberately allowed this: a
         * customer with more machines than the cluster has nodes has to share
         * eventually. Re-applying the rule here would refuse an order the
         * scheduler had already decided to take.
         */
        $this->asWorkerB(fn (): ComputeNode => app(ReserveNodeCapacity::class)->execute(
            $this->node,
            $machine,
            customerId: (string) $this->customer->getKey(),
            antiAffinityLimit: null,
        ));

        $this->assertSame(2, $this->freshNode()->vm_count);
    }

    /**
     * The machine row provisioning writes in the same transaction as the
     * reservation — the thing the next worker's re-count reads.
     */
    private function recordMachine(): void
    {
        $service = Service::factory()->create(['customer_id' => $this->customer->getKey()]);

        VirtualMachine::factory()->forService($service)->onNode($this->node)->create();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $worker
     * @return TReturn
     */
    private function asWorkerB(callable $worker): mixed
    {
        DB::setDefaultConnection(self::SECOND_CONNECTION);

        try {
            return $worker();
        } finally {
            DB::setDefaultConnection($this->defaultConnection);
        }
    }

    private function freshNode(): ComputeNode
    {
        return ComputeNode::on($this->defaultConnection)->findOrFail($this->node->getKey());
    }
}
