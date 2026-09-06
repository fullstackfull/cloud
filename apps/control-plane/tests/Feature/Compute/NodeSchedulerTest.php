<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\DTOs\PlacementRequest;
use Lynomia\Modules\Compute\Domain\Enums\ClusterStatus;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\PlacementRejectionReason;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Domain\Exceptions\NoCapacityAvailableException;
use Lynomia\Modules\Compute\Domain\Services\NodeScheduler;
use Lynomia\Modules\Compute\Domain\ValueObjects\PlacementRejection;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Placement is where every capacity mistake the platform can make originates,
 * and all of them are invisible until something else goes wrong: a fleet that
 * fills node 1 first looks fine until node 1 dies with every customer on it,
 * and a scheduler that ignores anti-affinity looks fine until the one customer
 * who paid for three machines loses all three at once.
 *
 * So these tests assert on the decisions rather than on the fact that a node
 * came back. The distinction the suite keeps returning to is exclusion versus
 * preference: an ineligible node must be gone, not merely last, because last
 * still wins when it is the only one left.
 */
final class NodeSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private ComputeCluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cluster = ComputeCluster::factory()->create();
    }

    #[Test]
    public function it_spreads_the_second_machine_onto_the_empty_node_rather_than_filling_the_first(): void
    {
        $first = $this->node('pve-01');
        $second = $this->node('pve-02');

        $resources = new VmResources(vcpu: 4, memoryMib: 8192, diskGib: 100);

        $one = app(NodeScheduler::class)->place($this->request($resources));

        // The placement is committed, exactly as provisioning would commit it.
        // Without this the second call would see an untouched fleet and the
        // test would prove nothing about spreading.
        app(ReserveNodeCapacity::class)->execute($one->node(), $resources);

        $two = app(NodeScheduler::class)->place($this->request($resources));

        $this->assertNotSame(
            (string) $one->node()->getKey(),
            (string) $two->node()->getKey(),
            'Both machines landed on the same node; the scheduler is filling rather than spreading.',
        );

        $this->assertEqualsCanonicalizing(
            [(string) $first->getKey(), (string) $second->getKey()],
            [(string) $one->node()->getKey(), (string) $two->node()->getKey()],
        );
    }

    #[Test]
    public function a_customers_second_machine_avoids_the_node_holding_their_first(): void
    {
        $customer = Customer::factory()->create();

        // The node the customer is already on is deliberately the more
        // attractive one: emptier storage and no other machines. If
        // anti-affinity were a mere preference, this is the node that would
        // win on score.
        $occupied = $this->node('pve-01');
        $alternative = $this->node('pve-02');
        $alternative->fill(['allocated_memory_mib' => 32768, 'allocated_cpu_cores' => 8, 'vm_count' => 4])->save();

        $this->placeExistingMachine($customer, $occupied);

        $decision = app(NodeScheduler::class)->place($this->request(
            new VmResources(vcpu: 2, memoryMib: 4096, diskGib: 50),
            customerId: (string) $customer->getKey(),
        ));

        $this->assertSame((string) $alternative->getKey(), (string) $decision->node()->getKey());

        $rejection = $this->rejectionFor($decision->rejections, (string) $occupied->getKey());
        $this->assertNotNull($rejection);
        $this->assertSame(PlacementRejectionReason::AntiAffinity, $rejection->reason);

        /*
         * The limit travels with the decision so the reservation can apply it
         * again under the node's row lock. Excluding here and nowhere else
         * would make the rule advisory: two of this customer's orders scoring
         * in the same millisecond both see an empty node and both take it.
         */
        $this->assertSame(1, $decision->antiAffinityLimit);
    }

    #[Test]
    public function anti_affinity_gives_way_when_the_customer_is_already_on_every_node(): void
    {
        $customer = Customer::factory()->create();

        $first = $this->node('pve-01');
        $second = $this->node('pve-02');

        $this->placeExistingMachine($customer, $first);
        $this->placeExistingMachine($customer, $second);

        /*
         * A customer with more machines than the cluster has nodes has to
         * share eventually. Refusing the order instead would be a worse
         * outcome than telling them where it landed, so the rule degrades to
         * the scoring term rather than excluding everything.
         */
        $decision = app(NodeScheduler::class)->place($this->request(
            new VmResources(vcpu: 2, memoryMib: 4096, diskGib: 50),
            customerId: (string) $customer->getKey(),
        ));

        $this->assertContains(
            (string) $decision->node()->getKey(),
            [(string) $first->getKey(), (string) $second->getKey()],
        );

        $antiAffinity = $decision->chosen->component('anti_affinity');
        $this->assertNotNull($antiAffinity);
        $this->assertSame(0.5, round($antiAffinity->value, 4), 'One machine already here should halve the term.');

        // Null says the rule was waived for this placement, so the reservation
        // must not re-apply it and refuse an order the scheduler took.
        $this->assertNull($decision->antiAffinityLimit);
    }

    /**
     * @return list<array{0: NodeStatus}>
     */
    public static function unschedulableStatuses(): array
    {
        return [[NodeStatus::Draining], [NodeStatus::Maintenance], [NodeStatus::Offline]];
    }

    #[Test]
    #[DataProvider('unschedulableStatuses')]
    public function a_node_that_is_not_active_is_excluded_entirely(NodeStatus $status): void
    {
        $unavailable = $this->node('pve-01');
        $unavailable->fill(['status' => $status])->save();

        $available = $this->node('pve-02');

        $decision = app(NodeScheduler::class)->place($this->request(
            new VmResources(vcpu: 2, memoryMib: 4096, diskGib: 50),
        ));

        $this->assertSame((string) $available->getKey(), (string) $decision->node()->getKey());

        // Excluded, not scored: a low score still wins when it is the only
        // score, and placing on a draining node is never the right answer.
        $this->assertNotContains(
            (string) $unavailable->getKey(),
            array_map(
                static fn (object $candidate): string => (string) $candidate->node->getKey(),
                $decision->candidates,
            ),
        );

        $rejection = $this->rejectionFor($decision->rejections, (string) $unavailable->getKey());
        $this->assertNotNull($rejection);
        $this->assertSame(PlacementRejectionReason::NodeNotActive, $rejection->reason);
    }

    #[Test]
    #[DataProvider('unschedulableStatuses')]
    public function a_cluster_of_only_unavailable_nodes_places_nothing(NodeStatus $status): void
    {
        $node = $this->node('pve-01');
        $node->fill(['status' => $status])->save();

        $this->expectException(NoCapacityAvailableException::class);

        app(NodeScheduler::class)->place($this->request(new VmResources(2, 4096, 50)));
    }

    #[Test]
    public function an_unhealthy_node_is_excluded_even_while_its_status_says_active(): void
    {
        $sick = $this->node('pve-01');
        $sick->fill(['is_healthy' => false])->save();

        $healthy = $this->node('pve-02');

        $decision = app(NodeScheduler::class)->place($this->request(new VmResources(2, 4096, 50)));

        $this->assertSame((string) $healthy->getKey(), (string) $decision->node()->getKey());
        $this->assertSame(
            PlacementRejectionReason::NodeUnhealthy,
            $this->rejectionFor($decision->rejections, (string) $sick->getKey())?->reason,
        );
    }

    #[Test]
    public function cpu_is_sold_up_to_the_overcommit_ratio_and_no_further(): void
    {
        // Eight physical cores at 2× is sixteen virtual ones, of which fifteen
        // are already committed.
        $node = $this->node('pve-01', ['cpu_cores' => 8, 'cpu_overcommit_ratio' => 2.0, 'allocated_cpu_cores' => 15]);

        $fits = app(NodeScheduler::class)->place($this->request(new VmResources(1, 4096, 50)));
        $this->assertSame((string) $node->getKey(), (string) $fits->node()->getKey());

        try {
            app(NodeScheduler::class)->place($this->request(new VmResources(2, 4096, 50)));
            $this->fail('A seventeenth virtual core was sold on a sixteen-core budget.');
        } catch (NoCapacityAvailableException $e) {
            $this->assertStringContainsString(
                PlacementRejectionReason::CpuOvercommitExceeded->value,
                (string) $e->context()['rejections'],
            );
        }
    }

    #[Test]
    public function memory_reserved_for_the_hypervisor_is_never_sold(): void
    {
        // The threshold is taken out of the way so that this test is about the
        // headroom alone: 10 GiB of memory with 10% headroom leaves 9216 MiB.
        config()->set('compute.scheduler.capacity_threshold_percent', 100);

        $this->node('pve-01', ['memory_mib' => 10240, 'memory_headroom_percent' => 10]);

        $fits = app(NodeScheduler::class)->place($this->request(new VmResources(2, 9216, 50)));
        $this->assertSame(0, $fits->chosen->assessment->freeMemoryMibAfter);

        try {
            app(NodeScheduler::class)->place($this->request(new VmResources(2, 9217, 50)));
            $this->fail('A machine was placed into the memory the hypervisor itself needs.');
        } catch (NoCapacityAvailableException $e) {
            $this->assertStringContainsString(
                PlacementRejectionReason::InsufficientMemory->value,
                (string) $e->context()['rejections'],
            );
        }
    }

    #[Test]
    public function the_capacity_threshold_keeps_a_node_below_full(): void
    {
        config()->set('compute.scheduler.capacity_threshold_percent', 85);

        // 10240 MiB less 10% headroom is 9216 usable; 85% of that is 7833.
        $this->node('pve-01', ['memory_mib' => 10240, 'memory_headroom_percent' => 10]);

        app(NodeScheduler::class)->place($this->request(new VmResources(2, 7833, 50)));

        try {
            app(NodeScheduler::class)->place($this->request(new VmResources(2, 7834, 50)));
            $this->fail('A machine was placed past the capacity threshold.');
        } catch (NoCapacityAvailableException $e) {
            // Reported as its own reason rather than as plain exhaustion:
            // "past the threshold" is a policy number an operator can change,
            // and "out of memory" is not.
            $this->assertStringContainsString(
                PlacementRejectionReason::CapacityThresholdExceeded->value,
                (string) $e->context()['rejections'],
            );
        }
    }

    #[Test]
    public function a_machine_is_never_placed_on_storage_of_the_wrong_class(): void
    {
        $spinning = ComputeNode::factory()->for($this->cluster, 'cluster')->named('pve-01')->create();
        ComputeStorage::factory()->onNode($spinning)->class(StorageClass::Hdd)->create(['provider_name' => 'local-hdd']);

        $flash = $this->node('pve-02');

        $decision = app(NodeScheduler::class)->place($this->request(
            new VmResources(2, 4096, 50),
            storageClass: StorageClass::Nvme,
        ));

        $this->assertSame((string) $flash->getKey(), (string) $decision->node()->getKey());
        $this->assertSame('local-nvme', $decision->storageName);
        $this->assertSame(
            PlacementRejectionReason::NoStorageOfRequiredClass,
            $this->rejectionFor($decision->rejections, (string) $spinning->getKey())?->reason,
        );
    }

    #[Test]
    public function a_pool_of_the_right_class_with_no_room_is_reported_as_a_full_pool(): void
    {
        $node = ComputeNode::factory()->for($this->cluster, 'cluster')->named('pve-01')->create();
        ComputeStorage::factory()->onNode($node)->available(10)->create();

        try {
            app(NodeScheduler::class)->place($this->request(new VmResources(2, 4096, 50)));
            $this->fail('A 50 GiB disk was placed on a pool with 10 GiB free.');
        } catch (NoCapacityAvailableException $e) {
            $this->assertStringContainsString(
                PlacementRejectionReason::InsufficientStorage->value,
                (string) $e->context()['rejections'],
            );
        }
    }

    #[Test]
    public function the_decision_explains_why_the_winner_won(): void
    {
        $this->node('pve-01');
        $this->node('pve-02', ['allocated_memory_mib' => 131072, 'vm_count' => 6]);

        $decision = app(NodeScheduler::class)->place($this->request(new VmResources(4, 8192, 100)));

        $components = array_map(
            static fn (object $component): string => $component->name,
            $decision->chosen->components,
        );

        $this->assertSame(
            ['memory_headroom', 'cpu_headroom', 'storage_headroom', 'spread', 'anti_affinity'],
            $components,
        );

        $this->assertGreaterThan(0.0, $decision->score());
        $this->assertNotNull($decision->runnerUp());
        $this->assertGreaterThan(
            $decision->runnerUp()?->total() ?? 0.0,
            $decision->score(),
            'The chosen node must be the highest scoring one.',
        );

        // The whole argument survives into a loggable structure, which is what
        // an operator reads months later.
        $explained = $decision->toArray();
        $this->assertSame(
            (string) $decision->node()->getKey(),
            $explained['chosen']['node_id'],
        );
        $this->assertArrayHasKey('memory_headroom', $explained['chosen']['components']);
    }

    #[Test]
    public function the_memory_term_is_measured_after_this_machine_lands_not_before(): void
    {
        config()->set('compute.scheduler.capacity_threshold_percent', 100);

        /*
         * Two nodes that are indistinguishable today: both half committed,
         * both with the same number of machines, the same cores and the same
         * storage. The only thing that can separate them is what the fleet
         * looks like once this machine has landed — and on the smaller node
         * this machine takes a much bigger bite.
         */
        $small = $this->node('pve-01', [
            'memory_mib' => 20480,
            'memory_headroom_percent' => 0,
            'allocated_memory_mib' => 10240,
            'vm_count' => 2,
        ]);
        $large = $this->node('pve-02', [
            'memory_mib' => 40960,
            'memory_headroom_percent' => 0,
            'allocated_memory_mib' => 20480,
            'vm_count' => 2,
        ]);

        $decision = app(NodeScheduler::class)->place($this->request(new VmResources(2, 8192, 50)));

        $this->assertSame((string) $large->getKey(), (string) $decision->node()->getKey());

        // Both nodes are 50% committed right now; only the post-placement
        // figure tells them apart.
        $this->assertSame(0.3, round((float) $decision->chosen->component('memory_headroom')?->value, 4));
        $this->assertSame(0.1, round((float) $decision->runnerUp()?->component('memory_headroom')?->value, 4));
        $this->assertSame((string) $small->getKey(), (string) $decision->runnerUp()?->node->getKey());
    }

    #[Test]
    public function an_explicitly_excluded_node_is_not_considered(): void
    {
        $failed = $this->node('pve-01');
        $retry = $this->node('pve-02');

        $decision = app(NodeScheduler::class)->place(new PlacementRequest(
            clusterId: (string) $this->cluster->getKey(),
            resources: new VmResources(2, 4096, 50),
            excludedNodeIds: [(string) $failed->getKey()],
        ));

        $this->assertSame((string) $retry->getKey(), (string) $decision->node()->getKey());
        $this->assertSame(
            PlacementRejectionReason::Excluded,
            $this->rejectionFor($decision->rejections, (string) $failed->getKey())?->reason,
        );
    }

    #[Test]
    public function a_cluster_that_is_not_accepting_placement_takes_nothing(): void
    {
        // The node itself is faultless, which is the point: a cluster in
        // maintenance is about to have its control plane upgraded, and every
        // node on it looks healthy right until it is not.
        $this->node('pve-01');
        $this->cluster->fill(['status' => ClusterStatus::Maintenance])->save();

        try {
            app(NodeScheduler::class)->place($this->request(new VmResources(2, 4096, 50)));
            $this->fail('A machine was placed into a cluster that is not taking work.');
        } catch (NoCapacityAvailableException $e) {
            $this->assertStringContainsString('cluster_not_accepting_placement', (string) $e->context()['rejections']);
            $this->assertSame('compute.no_capacity_available', $e->errorCode());
            $this->assertSame(503, $e->httpStatus());
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function node(string $name, array $attributes = []): ComputeNode
    {
        $node = ComputeNode::factory()
            ->for($this->cluster, 'cluster')
            ->named($name)
            ->create($attributes);

        ComputeStorage::factory()->onNode($node)->create();

        return $node;
    }

    private function request(
        VmResources $resources,
        ?string $customerId = null,
        StorageClass $storageClass = StorageClass::Nvme,
    ): PlacementRequest {
        return new PlacementRequest(
            clusterId: (string) $this->cluster->getKey(),
            resources: $resources,
            customerId: $customerId,
            storageClass: $storageClass,
        );
    }

    /**
     * A machine this customer already runs, recorded the way provisioning
     * records one: a service that owns it and a row pinned to a node.
     */
    private function placeExistingMachine(Customer $customer, ComputeNode $node): void
    {
        $service = Service::factory()->create(['customer_id' => $customer->getKey()]);

        VirtualMachine::factory()->forService($service)->onNode($node)->create();
    }

    /**
     * @param  list<PlacementRejection>  $rejections
     */
    private function rejectionFor(array $rejections, string $nodeId): ?PlacementRejection
    {
        foreach ($rejections as $rejection) {
            if ($rejection->nodeId === $nodeId) {
                return $rejection;
            }
        }

        return null;
    }
}
