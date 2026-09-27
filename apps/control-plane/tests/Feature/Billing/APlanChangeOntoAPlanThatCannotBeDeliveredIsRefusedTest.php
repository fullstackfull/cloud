<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Domain\Services\NodeCapacityPolicy;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentAttempt;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuysSharedHosting;
use Tests\TestCase;

/**
 * F-07 on the plan-change path: a plan the change cannot be delivered onto is
 * refused before any money moves, and a change accepted before its plan
 * stopped being deliverable cannot be paid for.
 *
 * The re-audit after round five reached it through routes an operator really
 * uses. A hosting plan and its price put on sale before its package is mapped
 * (the documented build order), or a mapped package withdrawn with
 * `DELETE /catalogue/hosting-packages/{id}` while its plan stays on sale:
 * checkout refused the plan (`checkout.not_deliverable`), and the plan-change
 * screen offered it with no refusal. The upgrade was accepted, its invoice
 * paid, the subscription moved and billed at the new price, and nothing was
 * queued at the panel.
 */
final class APlanChangeOntoAPlanThatCannotBeDeliveredIsRefusedTest extends TestCase
{
    use BuysSharedHosting;
    use RefreshDatabase;

    private Customer $customer;

    private User $user;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->user = User::factory()->create();
        $this->customer->members()->create(['user_id' => $this->user->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);

        $this->operator = User::factory()->create();
        $this->operator->syncRoles([Role::BillingAdmin->value]);
    }

    #[Test]
    public function a_hosting_plan_put_on_sale_before_its_package_is_refused_on_the_options_screen_and_the_change(): void
    {
        $subscription = $this->hostingSubscription();
        [$planId, $priceId] = $this->operatorPutsAPlanOnSale($subscription);

        $this->assertRefusedEverywhere($subscription, $planId, $priceId);
    }

    #[Test]
    public function a_hosting_plan_whose_package_was_withdrawn_is_refused_on_the_options_screen_and_the_change(): void
    {
        $subscription = $this->hostingSubscription();
        [$planId, $priceId] = $this->operatorPutsAPlanOnSale($subscription);

        $package = $this->actingAs($this->operator)->postJson('/api/admin/catalogue/hosting-packages', [
            'slug' => 'hosting-agency-withdrawn', 'panel_package_name' => 'lyn_agency', 'plan_id' => $planId,
            'disk_quota_mib' => 204_800, 'is_active' => true,
        ])->assertCreated();

        // On sale with its package: offered.
        $this->assertSame([], $this->optionFor($subscription, $planId)['refusals']);

        $this->actingAs($this->operator)->deleteJson('/api/admin/catalogue/hosting-packages/'.$package->json('data.id'))->assertOk();

        $this->assertRefusedEverywhere($subscription, $planId, $priceId);
    }

    #[Test]
    public function a_change_whose_package_was_withdrawn_after_it_was_accepted_cannot_be_paid_by_card(): void
    {
        [$subscription, $invoice] = $this->anAcceptedUpgradeWhosePackageIsThenWithdrawn();

        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices/'.$invoice->getKey().'/payments', [])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invoice.plan_change_not_deliverable');

        $this->assertSame(0, PaymentAttempt::query()->where('invoice_id', $invoice->getKey())->count(), 'A payment was opened for a plan change that cannot be delivered.');
        $this->assertSame(0, Transaction::query()->where('invoice_id', $invoice->getKey())->count());
        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()?->status);
    }

    #[Test]
    public function a_change_whose_package_was_withdrawn_after_it_was_accepted_cannot_be_paid_from_the_wallet(): void
    {
        [, $invoice] = $this->anAcceptedUpgradeWhosePackageIsThenWithdrawn();

        $ledger = app(WalletLedger::class);
        $wallet = $ledger->walletFor($this->customer, 'KWD');
        $ledger->credit(wallet: $wallet, amount: Money::ofMinor(50_000, 'KWD'), kind: WalletTransactionKind::Topup, description: 'Top-up');

        $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'pay-undeliverable-change')
            ->postJson('/api/v1/invoices/'.$invoice->getKey().'/wallet-credit')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invoice.plan_change_not_deliverable');

        $this->assertSame(50_000, $ledger->balance($wallet->fresh() ?? $wallet)->minorUnits(), 'The wallet paid for a plan change that cannot be delivered.');
        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()?->status);
    }

    #[Test]
    public function a_change_whose_plan_is_still_deliverable_is_paid_as_before(): void
    {
        // The control for the two above: nothing withdrawn, the payment opens.
        $subscription = $this->hostingSubscription();
        [$planId, $priceId] = $this->operatorPutsAPlanOnSale($subscription);
        $this->operatorMapsAPackage($planId);

        $this->changePlan($subscription, $planId, $priceId, 'upgrade-deliverable')->assertOk();
        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->sole();

        $this->actingAs($this->user)->postJson('/api/v1/invoices/'.$invoice->getKey().'/payments', [])->assertCreated();
    }

    #[Test]
    public function a_vps_service_with_no_machine_is_not_sold_a_resize(): void
    {
        [$subscription, $large] = $this->vpsSubscription(withMachine: false);

        $this->assertContains('not_deliverable', $this->optionFor($subscription, (string) $large->getKey())['refusals']);

        $this->changePlan($subscription, (string) $large->getKey(), $this->priceOf($large), 'vps-no-machine')
            ->assertStatus(409)
            ->assertJsonPath('error.details.refusals', 'not_deliverable');

        $this->assertSame(0, Invoice::query()->where('subscription_id', $subscription->getKey())->count());
    }

    #[Test]
    public function a_vps_upgrade_whose_growth_the_node_cannot_hold_is_refused(): void
    {
        /*
         * The resize grows the machine where it runs. On a node already
         * committed to within 1 GiB of its schedulable memory, a 4 GiB growth
         * fails at the resize as capacity, with the upgrade paid for; it is
         * refused before the money moves. The same node with room: offered.
         */
        [$subscription, $large, $node] = $this->vpsSubscriptionOnANode();

        $policy = app(NodeCapacityPolicy::class);
        $node->forceFill(['allocated_memory_mib' => $policy->schedulableMemoryMib($node) - 1_024])->save();

        $this->assertSame(['not_deliverable'], $this->optionFor($subscription, (string) $large->getKey())['refusals']);
        $this->changePlan($subscription, (string) $large->getKey(), $this->priceOf($large), 'vps-no-room')
            ->assertStatus(409)
            ->assertJsonPath('error.details.refusals', 'not_deliverable');
        $this->assertSame(0, Invoice::query()->where('subscription_id', $subscription->getKey())->count());

        $node->forceFill(['allocated_memory_mib' => 0, 'allocated_cpu_cores' => $node->usableCpuCores() - 1])->save();
        $this->assertSame(['not_deliverable'], $this->optionFor($subscription, (string) $large->getKey())['refusals'], 'A vCPU growth the node cannot hold was offered.');

        $node->forceFill(['allocated_cpu_cores' => 0, 'allocated_storage_gib' => $node->storage_gib - 10])->save();
        $this->assertSame(['not_deliverable'], $this->optionFor($subscription, (string) $large->getKey())['refusals'], 'A disk growth the node cannot hold was offered.');

        $node->forceFill(['allocated_storage_gib' => 0])->save();
        $this->assertSame([], $this->optionFor($subscription, (string) $large->getKey())['refusals']);
    }

    #[Test]
    public function a_vps_resize_is_not_refused_for_what_only_a_new_machine_needs(): void
    {
        /*
         * The target plan has no placement checkout would sell (no cluster,
         * no address pool), and checkout refuses it. A resize is made to the
         * machine where it runs and reads none of that, so the plan change is
         * delivered and is not refused on it.
         */
        [$subscription, $large] = $this->vpsSubscription(withMachine: true);

        $this->actingAs($this->user)
            ->postJson('/api/v1/orders/quote', [
                'items' => [['plan_id' => (string) $large->getKey(), 'quantity' => 1]],
                'billing_period' => 'monthly',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'checkout.not_deliverable');

        $this->assertSame([], $this->optionFor($subscription, (string) $large->getKey())['refusals']);
        $this->changePlan($subscription, (string) $large->getKey(), $this->priceOf($large), 'vps-resize')->assertOk();
    }

    // -----------------------------------------------------------------

    private function assertRefusedEverywhere(Subscription $subscription, string $planId, string $priceId): void
    {
        // Checkout refuses the plan: the rule the change is held to.
        $this->actingAs($this->user)
            ->postJson('/api/v1/orders/quote', [
                'items' => [['plan_id' => $planId, 'quantity' => 1, 'domain' => 'new-buyer.example.test']],
                'billing_period' => 'monthly',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'checkout.not_deliverable');

        $this->assertSame(['not_deliverable'], $this->optionFor($subscription, $planId)['refusals'], 'The options screen offered a plan the change cannot be delivered onto.');

        $this->changePlan($subscription, $planId, $priceId, 'upgrade-onto-undeliverable')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'subscription.plan_change_refused')
            ->assertJsonPath('error.details.refusals', 'not_deliverable');

        $fresh = $subscription->fresh();
        $this->assertNotSame($planId, (string) $fresh?->plan_id, 'The subscription moved onto a plan nothing can deliver.');
        $this->assertSame(1_500, $fresh?->recurring_amount_minor);
        $this->assertSame(0, Invoice::query()->where('subscription_id', $subscription->getKey())->count(), 'An upgrade nothing can deliver was invoiced.');
    }

    /**
     * @return array{0: Subscription, 1: Invoice}
     */
    private function anAcceptedUpgradeWhosePackageIsThenWithdrawn(): array
    {
        $subscription = $this->hostingSubscription();
        [$planId, $priceId] = $this->operatorPutsAPlanOnSale($subscription);
        $package = $this->operatorMapsAPackage($planId);

        $this->changePlan($subscription, $planId, $priceId, 'upgrade-then-withdrawn')->assertOk();

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->sole();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);

        $this->actingAs($this->operator)->deleteJson('/api/admin/catalogue/hosting-packages/'.$package)->assertOk();

        return [$subscription, $invoice];
    }

    private function hostingSubscription(): Subscription
    {
        $this->buySharedHosting($this->customer, $this->sharedHostingPlan('starter'));

        return Subscription::query()->where('customer_id', $this->customer->getKey())->sole();
    }

    /**
     * An active, public hosting plan and its price, through the operator's
     * routes - and no package, which is the order the catalogue is built in.
     *
     * @return array{0: string, 1: string}
     */
    private function operatorPutsAPlanOnSale(Subscription $subscription): array
    {
        $plan = $this->actingAs($this->operator)->postJson('/api/admin/catalogue/plans', [
            'product_id' => (string) $subscription->plan()->firstOrFail()->product_id,
            'slug' => 'hosting-agency-'.uniqid(),
            'name' => ['en' => 'Agency', 'ar' => 'وكالة'],
            'resources' => ['disk_quota_mib' => 204_800, 'bandwidth_quota_mib' => 1_024_000, 'max_databases' => 50],
            'is_active' => true,
            'is_public' => true,
        ])->assertCreated();

        $planId = (string) $plan->json('data.id');

        $price = $this->actingAs($this->operator)->postJson('/api/admin/catalogue/plans/'.$planId.'/prices', [
            'currency' => 'KWD', 'billing_period' => 'monthly', 'recurring_amount_minor' => 9_000, 'setup_amount_minor' => 0, 'is_active' => true,
        ])->assertCreated();

        return [$planId, (string) $price->json('data.prices.0.id')];
    }

    private function operatorMapsAPackage(string $planId): string
    {
        return (string) $this->actingAs($this->operator)->postJson('/api/admin/catalogue/hosting-packages', [
            'slug' => 'hosting-agency-'.uniqid(), 'panel_package_name' => 'lyn_agency', 'plan_id' => $planId,
            'disk_quota_mib' => 204_800, 'is_active' => true,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function optionFor(Subscription $subscription, string $planId): array
    {
        $options = $this->actingAs($this->user)
            ->getJson('/api/v1/subscriptions/'.$subscription->getKey().'/plan-options')
            ->assertOk()
            ->json('data');

        $option = collect($options)->first(static fn (array $o): bool => ($o['plan']['id'] ?? $o['plan_id'] ?? null) === $planId);
        $this->assertIsArray($option, 'The plan is not on the options screen at all.');

        return $option;
    }

    private function changePlan(Subscription $subscription, string $planId, string $priceId, string $key): TestResponse
    {
        return $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/subscriptions/'.$subscription->getKey().'/plan', ['plan_id' => $planId, 'price_id' => $priceId]);
    }

    /**
     * @return array{0: Subscription, 1: Plan, 2: ComputeNode}
     */
    private function vpsSubscriptionOnANode(): array
    {
        [$subscription, $large] = $this->vpsSubscription(withMachine: true);

        /** @var VirtualMachine $machine */
        $machine = VirtualMachine::query()->whereHas('service', fn ($s) => $s->where('subscription_id', $subscription->getKey()))->sole();

        return [$subscription, $large, $machine->node()->firstOrFail()];
    }

    /**
     * @return array{0: Subscription, 1: Plan}
     */
    private function vpsSubscription(bool $withMachine): array
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        $small = $this->vpsPlan($product, 'small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40], 9_000);
        $large = $this->vpsPlan($product, 'large', ['vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 80], 18_000);

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::now()->subDays(10))
            ->create([
                'customer_id' => $this->customer->getKey(),
                'plan_id' => $small->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => 9_000,
            ]);

        $service = Service::factory()->active()->create([
            'customer_id' => $this->customer->getKey(),
            'kind' => 'vps',
            'subscription_id' => $subscription->getKey(),
            'resources' => ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40],
        ]);

        if ($withMachine) {
            $node = ComputeNode::factory()->create(['cluster_id' => ComputeCluster::factory()->create()->getKey()]);
            VirtualMachine::factory()->onNode($node, 900)->forService($service)->create(['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40]);
        }

        return [$subscription, $large];
    }

    /**
     * @param  array<string, int>  $resources
     */
    private function vpsPlan(Product $product, string $slug, array $resources, int $minor): Plan
    {
        $plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            'slug' => 'vps-'.$slug.'-'.uniqid(),
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

    private function priceOf(Plan $plan): string
    {
        return (string) PlanPrice::query()->where('plan_id', $plan->getKey())->sole()->getKey();
    }
}
