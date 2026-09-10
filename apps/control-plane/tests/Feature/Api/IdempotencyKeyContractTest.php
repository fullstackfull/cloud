<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one contract every guarded mutation on the customer API keeps.
 *
 *   the client generates a key
 *   → sends it as the Idempotency-Key header
 *   → the server reads exactly that header
 *   → a retry with the same key returns the same logical result
 *   → a different key is a new attempt, where the action allows one
 *   → no key at all is refused, with an error that names the header
 *
 * Each route family has its own endpoint suite proving its own semantics in
 * depth. This file exists so the contract is asserted in ONE place across all
 * of them, because the defect that motivated it was a client sending the key
 * in the body to five of these six routes and being answered, five times, with
 * a validation error about a field that was not on the screen.
 */
final class IdempotencyKeyContractTest extends TestCase
{
    use RefreshDatabase;

    private ?ComputeNode $node = null;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunProvisioningJob::class]);
        $this->freezeTime();
    }

    /**
     * @return array{0: Customer, 1: User}
     */
    private function account(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();
        $customer->members()->create(['user_id' => $user->getKey(), 'role' => CustomerRole::Owner, 'accepted_at' => now()]);

        return [$customer, $user];
    }

    /**
     * @param  array<string, mixed>  $resources
     */
    private function plan(Product $product, string $slug, array $resources, int $minor): Plan
    {
        $plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            'slug' => $slug,
            'resources' => $resources,
            'is_active' => true,
            'is_public' => true,
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $minor,
            'setup_amount_minor' => 0,
        ]);

        return $plan;
    }

    private function machine(Customer $customer, string $hostname = 'web-01'): VirtualMachine
    {
        $this->node ??= ComputeNode::factory()->create(['cluster_id' => ComputeCluster::factory()->create()->getKey()]);

        $service = Service::factory()->create(['customer_id' => $customer->getKey(), 'kind' => 'vps', 'status' => ServiceStatus::Active]);

        return VirtualMachine::factory()->onNode($this->node)->forService($service)->create(['hostname' => $hostname]);
    }

    /**
     * Missing header → 422 with the contract's own code, naming the header,
     * and no side effect. One assertion helper so every family says the same.
     */
    private function assertRefusedWithoutKey(User $user, string $url, array $body = []): void
    {
        $this->actingAs($user)
            ->postJson($url, $body)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'request.idempotency_key_rejected')
            ->assertJsonPath('error.details.header', 'Idempotency-Key')
            ->assertJsonPath('error.details.field', 'idempotency_key');
    }

    #[Test]
    public function placing_an_order(): void
    {
        [, $user] = $this->account();
        $plan = $this->plan(Product::factory()->create(['kind' => 'vps']), 'cx-1', ['vcpu' => 1, 'memory_mib' => 2048, 'disk_gib' => 20], 3000);
        $basket = ['items' => [['plan_id' => $plan->getKey(), 'quantity' => 1]], 'billing_period' => 'monthly'];

        $this->assertRefusedWithoutKey($user, '/api/v1/orders', $basket);
        $this->assertSame(0, Order::query()->count());

        $first = $this->actingAs($user)->withHeader('Idempotency-Key', 'basket-0001')->postJson('/api/v1/orders', $basket)->assertCreated();
        $again = $this->actingAs($user)->withHeader('Idempotency-Key', 'basket-0001')->postJson('/api/v1/orders', $basket);

        // Same key: the same order comes back and no second one exists.
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, Order::query()->count());

        // Different key: a deliberate second purchase.
        $this->actingAs($user)->withHeader('Idempotency-Key', 'basket-0002')->postJson('/api/v1/orders', $basket)->assertCreated();
        $this->assertSame(2, Order::query()->count());

        // A key in the body alone is no key at all. (withHeader() persists for
        // the rest of a test, so the header set above is dropped first.)
        $this->flushHeaders();
        $this->actingAs($user)
            ->postJson('/api/v1/orders', $basket + ['idempotency_key' => 'basket-0003'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'request.idempotency_key_rejected');
        $this->assertSame(2, Order::query()->count());
    }

    #[Test]
    public function changing_a_machines_power(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machine($customer);
        $url = '/api/v1/vps/'.$machine->getKey().'/power';

        $this->assertRefusedWithoutKey($user, $url, ['action' => 'reboot']);
        $this->assertSame(0, ProvisioningJob::query()->count());

        $first = $this->actingAs($user)->withHeader('Idempotency-Key', 'reboot-0001')->postJson($url, ['action' => 'reboot'])->assertStatus(202);
        $again = $this->actingAs($user)->withHeader('Idempotency-Key', 'reboot-0001')->postJson($url, ['action' => 'reboot'])->assertStatus(202);

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, ProvisioningJob::query()->count());
        Queue::assertPushed(RunProvisioningJob::class, 1);

        // A different key is a new attempt — and here the action does NOT
        // allow one while the first is still queued, so it is refused rather
        // than silently replayed. Either way it is not a second job.
        $this->actingAs($user)->withHeader('Idempotency-Key', 'reboot-0002')->postJson($url, ['action' => 'reboot'])->assertStatus(409);
        $this->assertSame(1, ProvisioningJob::query()->count());
    }

    #[Test]
    public function rebuilding_a_machine(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machine($customer, 'db-01');
        $url = '/api/v1/vps/'.$machine->getKey().'/reinstall';

        $this->assertRefusedWithoutKey($user, $url, ['confirm_hostname' => 'db-01']);
        $this->assertSame(0, ProvisioningJob::query()->count());

        $first = $this->actingAs($user)->withHeader('Idempotency-Key', 'rebuild-0001')->postJson($url, ['confirm_hostname' => 'db-01'])->assertStatus(202);
        $again = $this->actingAs($user)->withHeader('Idempotency-Key', 'rebuild-0001')->postJson($url, ['confirm_hostname' => 'db-01'])->assertStatus(202);

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, ProvisioningJob::query()->count());
    }

    #[Test]
    public function changing_a_subscriptions_plan(): void
    {
        [$customer, $user] = $this->account();
        $product = Product::factory()->create(['kind' => 'vps']);
        $small = $this->plan($product, 'small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40], 9_000);
        $large = $this->plan($product, 'large', ['vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 80], 18_000);
        $largePrice = PlanPrice::query()->where('plan_id', $large->getKey())->sole();

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::now()->subDays(10))
            ->create([
                'customer_id' => $customer->getKey(),
                'plan_id' => $small->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => 9_000,
            ]);

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'subscription_id' => $subscription->getKey(),
            'resources' => ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40],
        ]);
        $this->node ??= ComputeNode::factory()->create(['cluster_id' => ComputeCluster::factory()->create()->getKey()]);
        VirtualMachine::factory()->onNode($this->node, 900)->forService($service)->create(['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40]);

        $url = "/api/v1/subscriptions/{$subscription->getKey()}/plan";
        $body = ['plan_id' => $large->getKey(), 'price_id' => $largePrice->getKey()];

        $this->assertRefusedWithoutKey($user, $url, $body);
        $this->assertSame($small->getKey(), $subscription->fresh()?->plan_id);

        $this->actingAs($user)->withHeader('Idempotency-Key', 'upgrade-0001')->postJson($url, $body)->assertSuccessful();
        $this->assertSame($large->getKey(), $subscription->fresh()?->plan_id);
        $resizes = ProvisioningJob::query()->count();
        $this->assertSame(1, $resizes);

        // The same key again: no second resize is queued.
        $this->actingAs($user)->withHeader('Idempotency-Key', 'upgrade-0001')->postJson($url, $body);
        $this->assertSame(1, ProvisioningJob::query()->count());
    }

    #[Test]
    public function paying_an_invoice_from_credit(): void
    {
        [$customer, $user] = $this->account();
        $ledger = app(WalletLedger::class);
        $wallet = $ledger->walletFor($customer, 'KWD');
        $ledger->credit(wallet: $wallet, amount: Money::ofMinor(30_000, 'KWD'), kind: WalletTransactionKind::Topup, description: 'Credit for the contract test');

        $invoice = Invoice::factory()->create(['customer_id' => $customer->getKey(), 'currency' => 'KWD']);
        $url = "/api/v1/invoices/{$invoice->getKey()}/wallet-credit";

        $this->assertRefusedWithoutKey($user, $url);
        $this->assertSame(30_000, (int) $wallet->fresh()?->balance_minor);

        $this->actingAs($user)->withHeader('Idempotency-Key', 'credit-0001')->postJson($url)->assertSuccessful();
        $this->actingAs($user)->withHeader('Idempotency-Key', 'credit-0001')->postJson($url)->assertSuccessful();

        // Debited once for the invoice's 9.000, not twice.
        $this->assertSame(21_000, (int) $wallet->fresh()?->balance_minor);
    }
}
