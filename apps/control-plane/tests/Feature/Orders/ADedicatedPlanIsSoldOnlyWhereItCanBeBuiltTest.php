<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Provisioning\Application\DTOs\PlacementResolution;
use Lynomia\Modules\Provisioning\Application\Services\LocalPlacementFeasibility;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where a Dedicated plan resolves, and what it is refused for before money moves.
 *
 * Each row names one candidate the Dedicated branch of
 * LocalPlacementFeasibility must not accept: a pool the plan names that the
 * estate would never pick itself, a pool in another building, and an install
 * profile the pool's subnets cannot give a gateway to.
 */
final class ADedicatedPlanIsSoldOnlyWhereItCanBeBuiltTest extends TestCase
{
    use RefreshDatabase;

    private const string HARDWARE = 'ded-standard-1';

    private Datacenter $datacenter;

    private IpPool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->datacenter = Datacenter::factory()->create();
        DedicatedServer::factory()->inDatacenter($this->datacenter)->profile(self::HARDWARE)->create();

        $this->pool = IpPool::factory()->create(['datacenter_id' => $this->datacenter->getKey()]);
        Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create(['ip_pool_id' => $this->pool->getKey()]);

        OsInstallProfile::factory()->create();
    }

    #[Test]
    public function the_estate_resolves_its_datacenter_its_pool_and_its_profile(): void
    {
        $resolution = $this->resolve();

        $this->assertTrue($resolution->isFeasible(), (string) $resolution->blockedReason);
        $this->assertSame((string) $this->datacenter->getKey(), $resolution->values['datacenter_id']);
        $this->assertSame((string) $this->pool->getKey(), $resolution->values['ip_pool_id']);
        $this->assertSame((string) OsInstallProfile::query()->sole()->getKey(), $resolution->values['os_install_profile_id']);
    }

    #[Test]
    public function a_named_management_pool_is_refused(): void
    {
        $management = IpPool::factory()->create([
            'datacenter_id' => $this->datacenter->getKey(),
            'scope' => IpPoolScope::Management,
        ]);

        $this->assertFalse($this->resolve(['ip_pool_id' => (string) $management->getKey()])->isFeasible());
    }

    #[Test]
    public function a_named_inactive_pool_is_refused(): void
    {
        $inactive = IpPool::factory()->inactive()->create(['datacenter_id' => $this->datacenter->getKey()]);

        $this->assertFalse($this->resolve(['ip_pool_id' => (string) $inactive->getKey()])->isFeasible());
    }

    #[Test]
    public function a_named_pool_that_does_not_exist_is_refused(): void
    {
        $this->assertFalse($this->resolve(['ip_pool_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->isFeasible());
    }

    #[Test]
    public function a_named_datacenter_that_holds_no_such_machine_is_refused(): void
    {
        $empty = Datacenter::factory()->create();

        $this->assertFalse($this->resolve(['datacenter_id' => (string) $empty->getKey()])->isFeasible());
        $this->assertFalse($this->resolve(['datacenter_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->isFeasible());
    }

    #[Test]
    public function a_pool_in_another_building_is_not_a_candidate_named_or_not(): void
    {
        $elsewhere = IpPool::factory()->create(['datacenter_id' => Datacenter::factory()->create()->getKey()]);

        // Named: refused.
        $this->assertFalse($this->resolve(['ip_pool_id' => (string) $elsewhere->getKey()])->isFeasible());

        // Not named, and the only customer pool in the machine's building
        // goes: the one elsewhere is not picked up in its place.
        $this->pool->forceFill(['is_active' => false])->save();

        $this->assertFalse($this->resolve()->isFeasible());
    }

    #[Test]
    public function a_profile_asking_for_a_gateway_is_refused_against_a_pool_holding_a_subnet_without_one(): void
    {
        Subnet::factory()->forBlock('198.51.100.16/29')->create([
            'ip_pool_id' => $this->pool->getKey(),
            'gateway' => null,
        ]);

        $resolution = $this->resolve();

        $this->assertFalse($resolution->isFeasible());
        $this->assertStringContainsString('gateway', (string) $resolution->blockedReason);

        // A profile that does not ask for one is placeable on the same pool.
        OsInstallProfile::query()->update(['is_active' => false]);
        OsInstallProfile::factory()->withoutPlaceholders()->create();

        $this->assertTrue($this->resolve()->isFeasible());
    }

    #[Test]
    public function checkout_refuses_a_plan_naming_a_management_pool_and_no_order_exists(): void
    {
        $management = IpPool::factory()->create([
            'datacenter_id' => $this->datacenter->getKey(),
            'scope' => IpPoolScope::Management,
        ]);

        $plan = $this->plan(['ip_pool_id' => (string) $management->getKey()]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 45_000,
            'setup_amount_minor' => 0,
        ]);

        try {
            app(PlaceOrder::class)->execute(
                Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']),
                new CheckoutRequest(
                    lines: [new CheckoutLine((string) $plan->getKey(), 1)],
                    billingPeriod: BillingPeriod::Monthly,
                    couponCode: null,
                    idempotencyKey: null,
                ),
            );
            $this->fail('A Dedicated plan naming a management pool was sold.');
        } catch (CheckoutRejectedException $e) {
            $this->assertSame('checkout.not_deliverable', $e->errorCode());
        }

        $this->assertSame(0, Order::query()->count());
    }

    /**
     * @param  array<string, mixed>  $constraints
     */
    private function resolve(array $constraints = []): PlacementResolution
    {
        return app(LocalPlacementFeasibility::class)->resolve($this->plan($constraints)->load('product'));
    }

    /**
     * @param  array<string, mixed>  $constraints
     */
    private function plan(array $constraints): Plan
    {
        return Plan::factory()->create([
            'product_id' => Product::factory()->create(['kind' => 'dedicated'])->getKey(),
            'resources' => ['hardware_profile' => self::HARDWARE, 'ipv4_count' => 1],
            'placement_constraints' => $constraints === [] ? null : $constraints,
        ]);
    }
}
