<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpReservation;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Application\Services\LocalPlacementFeasibility;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * A customer block registered with no gateway is legitimate — a dedicated
 * server's install profile can carry a default route of its own — but a VPS
 * has nothing to take one from. Its cloud-init network line is the subnet's
 * gateway, and a block with none sent `ip=203.0.113.1/29,gw=`: a machine
 * built, billed and handed over with no default route, while the preflight
 * counted the block's addresses as ones a customer machine can be given.
 *
 * So for a VPS an address in a block with no gateway is not one it can be
 * given: the sale refuses a pool that holds nothing else, the preflight does
 * not count it, the build's reservation passes it over, and a build that
 * reaches the hypervisor with one anyway (the gateway gone between the
 * reservation and the build) is refused permanently rather than built.
 */
final class AVpsIsNeverGivenAnAddressWithNoRouteOutTest extends TestCase
{
    use DrivesVpsCreatesThroughTheOperatorPath;
    use RefreshDatabase;

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->setUpTheCluster();
        $this->hypervisor->loseTheAnswerToCreates = false;

        $this->hypervisor->atTheMomentOfCreate = function (CreateVmRequest $request): void {
            $this->sent[] = (string) $request->cloudInit?->ipConfig;
        };
    }

    #[Test]
    public function the_build_passes_over_a_block_with_no_gateway_for_one_that_has_one(): void
    {
        // Its hosts (.1 to .6) sort before the fixture block's in the text
        // order the allocator takes addresses in.
        $this->aBlockWithNoGateway();

        $job = $this->createJob();
        $this->runWorker($job);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()->status, (string) $job->fresh()->last_error);
        $this->assertCount(1, $this->sent);
        $this->assertMatchesRegularExpression('#^ip=198\.51\.100\.\d+/29,gw=198\.51\.100\.9$#', $this->sent[0]);
    }

    #[Test]
    public function a_pool_holding_only_blocks_with_no_gateway_builds_nothing(): void
    {
        $this->aBlockWithNoGateway();
        $this->onlyTheBlockWithNoGatewayHasAddresses();

        $job = $this->createJob();
        $result = app(CreateVpsHandler::class)->execute($job);

        $this->assertFalse($result->successful);
        $this->assertSame('ipam.pool_exhausted', $result->errorCode);
        $this->assertSame(FailureClass::Capacity, $result->failureClass);
        $this->assertSame([], $this->sent, 'A machine was sent to the hypervisor with no default route.');
        $this->assertSame(0, IpReservation::query()->where('provisioning_job_id', $job->id)->count());
    }

    #[Test]
    public function a_gateway_gone_by_the_time_of_the_build_is_refused_and_not_sent_empty(): void
    {
        /*
         * The reservation takes only addresses in blocks with a gateway, so
         * this is the race: the row changed between the reservation and the
         * build's read of it. Nothing on the platform clears a gateway, which
         * is why a listener does it here.
         */
        IpReservation::created(static function (IpReservation $reservation): void {
            DB::table('subnets')
                ->where('id', IpAddress::query()->findOrFail($reservation->ip_address_id)->subnet_id)
                ->update(['gateway' => null]);
        });

        $result = app(CreateVpsHandler::class)->execute($this->createJob());

        $this->assertFalse($result->successful);
        $this->assertSame(FailureClass::Permanent, $result->failureClass);
        $this->assertSame('vps.subnet_has_no_gateway', $result->errorCode);
        $this->assertSame([], $this->sent, 'A machine was sent to the hypervisor with no default route.');
        $this->assertSame(0, VirtualMachine::query()->count());
    }

    #[Test]
    public function the_preflight_does_not_count_an_address_with_no_route_out(): void
    {
        $this->aBlockWithNoGateway();
        $this->onlyTheBlockWithNoGatewayHasAddresses();

        Artisan::call('infra:preflight', ['--mode' => 'simulation', '--product' => 'vps', '--json' => true]);

        /** @var array{checks: list<array{id: string, status: string, summary: string}>} $report */
        $report = json_decode(Artisan::output(), true);
        $finding = collect($report['checks'])->firstWhere('id', 'mapping.network');

        $this->assertNotNull($finding);
        $this->assertSame('fail', $finding['status'], $finding['summary']);
        $this->assertStringContainsString('gateway', $finding['summary']);
    }

    #[Test]
    public function a_pool_holding_only_blocks_with_no_gateway_is_not_sold_a_vps(): void
    {
        $this->aBlockWithNoGateway();
        $this->onlyTheBlockWithNoGatewayHasAddresses();

        $product = Product::factory()->create(['is_active' => true]);
        $plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            'is_active' => true,
            'is_public' => true,
            'placement_constraints' => [
                'cluster_id' => (string) $this->cluster->getKey(),
                'ip_pool_id' => (string) $this->pool->getKey(),
            ],
        ]);
        VmTemplate::factory()->create([
            'cluster_id' => $this->cluster->getKey(),
            'is_active' => true,
        ]);

        $resolution = app(LocalPlacementFeasibility::class)->resolveForSale($plan);

        $this->assertFalse($resolution->isFeasible(), 'A VPS was sold on a pool whose every address has no default route.');
        $this->assertStringContainsString('gateway', (string) $resolution->blockedReason);
    }

    /**
     * A customer block on the fixture's customer network, in the fixture's
     * pool, registered with no gateway: what the operator's subnet route
     * accepts, and a dedicated server can use.
     */
    private function aBlockWithNoGateway(): Subnet
    {
        $block = Subnet::factory()->forBlock('198.51.100.0/29')->withoutGateway()->create([
            'ip_pool_id' => $this->pool->getKey(),
            'network_id' => Subnet::query()->where('cidr', '198.51.100.8/29')->value('network_id'),
        ]);
        app(SeedSubnetAddresses::class)->execute($block);

        return $block;
    }

    private function onlyTheBlockWithNoGatewayHasAddresses(): void
    {
        IpAddress::query()
            ->whereIn('subnet_id', Subnet::query()->whereNotNull('gateway')->select('id'))
            ->update(['status' => IpAddressStatus::Unavailable->value]);
    }
}
