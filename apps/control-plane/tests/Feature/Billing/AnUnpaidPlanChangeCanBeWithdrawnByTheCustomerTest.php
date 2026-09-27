<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
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
 * A customer is never locked in by a plan change they have not paid for
 * (N2 / X7-2, the re-audit after round six).
 *
 * Round six refused the payment of a proration invoice whose change could no
 * longer be delivered - the package behind the hosting plan withdrawn, the
 * VPS's node filled - after the change was accepted. Refused, and nothing
 * else: the invoice stayed open, so every further plan change was refused
 * (`invoice_outstanding`), the subscription stayed moved onto the plan it
 * could not be given and was billed for it, and nothing but an operator's
 * void or the next renewal ended it. GET /invoices/{id} said `is_payable:
 * true` while both payments refused.
 *
 * The customer can now withdraw an unpaid plan change
 * (POST /invoices/{invoice}/withdraw-plan-change): what its invoice holds
 * goes back to the wallet, the invoice is voided, and the subscription goes
 * back to the plan and the price it came from (the void-and-restore path an
 * operator's void and a renewal's lapse take). And `is_payable` says what
 * payment does: false while the change cannot be delivered, with
 * `plan_change_withdrawable` saying the way out.
 */
final class AnUnpaidPlanChangeCanBeWithdrawnByTheCustomerTest extends TestCase
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
        config(['payments.fake.state_path' => storage_path('framework/testing/fake-payments-'.uniqid().'.json')]);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->user = User::factory()->create();
        $this->customer->members()->create(['user_id' => $this->user->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);

        $this->operator = User::factory()->create();
        $this->operator->syncRoles([Role::BillingAdmin->value]);
    }

    protected function tearDown(): void
    {
        $path = config('payments.fake.state_path');

        if (is_string($path) && is_file($path)) {
            unlink($path);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_hosting_change_whose_package_was_withdrawn_is_not_payable_and_can_be_withdrawn(): void
    {
        $this->buySharedHosting($this->customer, $this->sharedHostingPlan('starter'));
        $subscription = Subscription::query()->where('customer_id', $this->customer->getKey())->sole();
        $fromPlanId = (string) $subscription->plan_id;
        $fromRecurring = (int) $subscription->recurring_amount_minor;

        [$planId, $priceId] = $this->operatorPutsAPlanOnSale($subscription);
        $package = $this->operatorMapsAPackage($planId);
        $this->changePlan($subscription, $planId, $priceId)->assertOk();
        $invoice = $this->openInvoiceOf($subscription);

        $this->actingAs($this->operator)->deleteJson('/api/admin/catalogue/hosting-packages/'.$package)->assertOk();

        // What the invoice says agrees with what paying it does.
        $this->actingAs($this->user)->getJson('/api/v1/invoices/'.$invoice->getKey())
            ->assertOk()
            ->assertJsonPath('data.is_payable', false)
            ->assertJsonPath('data.plan_change_withdrawable', true);

        // And the list says the same, from its once-per-page read.
        $listed = collect($this->actingAs($this->user)->getJson('/api/v1/invoices')->assertOk()->json('data'))->firstWhere('id', (string) $invoice->getKey());
        $this->assertFalse($listed['is_payable']);
        $this->assertTrue($listed['plan_change_withdrawable']);

        $card = $this->actingAs($this->user)->postJson('/api/v1/invoices/'.$invoice->getKey().'/payments')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invoice.plan_change_not_deliverable');
        $this->assertStringContainsString('You can withdraw the change from this invoice', (string) $card->json('error.message'), 'The refusal does not tell the customer the way out.');

        $ledger = app(WalletLedger::class);
        $wallet = $ledger->walletFor($this->customer, 'KWD');
        $ledger->credit(wallet: $wallet, amount: Money::ofMinor(50_000, 'KWD'), kind: WalletTransactionKind::Topup, description: 'Top-up');
        $this->actingAs($this->user)->withHeader('Idempotency-Key', 'pay-undeliverable')
            ->postJson('/api/v1/invoices/'.$invoice->getKey().'/wallet-credit')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invoice.plan_change_not_deliverable');

        // The way out.
        $this->withdraw($invoice)
            ->assertOk()
            ->assertJsonPath('data.status', 'void')
            ->assertJsonPath('data.is_payable', false)
            ->assertJsonPath('data.plan_change_withdrawable', false);

        $fresh = $subscription->fresh();
        $this->assertSame($fromPlanId, (string) $fresh?->plan_id, 'The subscription was left on the plan it could not be given.');
        $this->assertSame($fromRecurring, $fresh?->recurring_amount_minor);
        $this->assertSame(50_000, $ledger->balance($wallet->fresh() ?? $wallet)->minorUnits());

        $this->assertNotContains('invoice_outstanding', $this->everyRefusalOn($subscription), 'The customer is still locked out of changing plan.');

        $entry = AuditEntry::query()->where('action', AuditAction::InvoiceVoided->value)->sole();
        $this->assertSame((string) $invoice->getKey(), (string) $entry->subject_id);
        $this->assertSame('plan_change_withdrawn', $entry->context['reason'] ?? null);
        $this->assertSame((string) $this->user->getKey(), (string) $entry->actor_id);

        // Nothing left to withdraw.
        $this->withdraw($invoice)->assertStatus(409)->assertJsonPath('error.code', 'invoice.plan_change_not_withdrawable');
    }

    #[Test]
    public function a_vps_change_whose_node_filled_is_not_payable_and_can_be_withdrawn(): void
    {
        [$subscription, $large, $node] = $this->vpsSubscriptionOnANode();

        $this->changePlan($subscription, (string) $large->getKey(), $this->priceOf($large))->assertOk();
        $invoice = $this->openInvoiceOf($subscription);

        $node->refresh()->forceFill(['allocated_memory_mib' => app(NodeCapacityPolicy::class)->schedulableMemoryMib($node) - 1_024])->save();

        $this->actingAs($this->user)->getJson('/api/v1/invoices/'.$invoice->getKey())
            ->assertOk()
            ->assertJsonPath('data.is_payable', false)
            ->assertJsonPath('data.plan_change_withdrawable', true);
        $this->actingAs($this->user)->postJson('/api/v1/invoices/'.$invoice->getKey().'/payments')->assertStatus(409);

        $this->withdraw($invoice)->assertOk()->assertJsonPath('data.status', 'void');

        $this->assertNotSame((string) $large->getKey(), (string) $subscription->fresh()?->plan_id);
        $this->assertSame(9_000, $subscription->fresh()?->recurring_amount_minor);
        $this->assertNotContains('invoice_outstanding', $this->everyRefusalOn($subscription));
    }

    #[Test]
    public function a_deliverable_unpaid_change_is_payable_and_can_be_withdrawn_with_its_part_payment_returned(): void
    {
        [$subscription, $large] = $this->vpsSubscriptionOnANode();

        $this->changePlan($subscription, (string) $large->getKey(), $this->priceOf($large))->assertOk();
        $invoice = $this->openInvoiceOf($subscription);

        $this->actingAs($this->user)->getJson('/api/v1/invoices/'.$invoice->getKey())
            ->assertOk()
            ->assertJsonPath('data.is_payable', true)
            ->assertJsonPath('data.plan_change_withdrawable', true);

        // Part of it paid from the wallet, then withdrawn: the part goes back.
        $ledger = app(WalletLedger::class);
        $wallet = $ledger->walletFor($this->customer, 'KWD');
        $ledger->credit(wallet: $wallet, amount: Money::ofMinor(100, 'KWD'), kind: WalletTransactionKind::Topup, description: 'Top-up');
        $this->actingAs($this->user)->withHeader('Idempotency-Key', 'part-pay')
            ->postJson('/api/v1/invoices/'.$invoice->getKey().'/wallet-credit')
            ->assertOk();
        $this->assertSame(0, $ledger->balance($wallet->fresh() ?? $wallet)->minorUnits());
        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()?->status);

        $this->withdraw($invoice)->assertOk()->assertJsonPath('data.status', 'void');

        $this->assertSame(100, $ledger->balance($wallet->fresh() ?? $wallet)->minorUnits(), 'The part payment of a withdrawn change was kept.');
        $this->assertSame(9_000, $subscription->fresh()?->recurring_amount_minor);
    }

    #[Test]
    public function an_invoice_that_bills_no_plan_change_is_not_withdrawn(): void
    {
        $this->buySharedHosting($this->customer, $this->sharedHostingPlan('starter'));
        $subscription = Subscription::query()->where('customer_id', $this->customer->getKey())->sole();

        /** @var Invoice $renewal */
        $renewal = Invoice::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => 1_500,
            'total_minor' => 1_500,
        ]);

        $this->actingAs($this->user)->getJson('/api/v1/invoices/'.$renewal->getKey())
            ->assertOk()
            ->assertJsonPath('data.is_payable', true)
            ->assertJsonPath('data.plan_change_withdrawable', false);

        $this->withdraw($renewal)->assertStatus(409)->assertJsonPath('error.code', 'invoice.plan_change_not_withdrawable');
        $this->assertSame(InvoiceStatus::Open, $renewal->fresh()?->status);

        // Another account's invoice is not found, as every invoice route answers.
        $stranger = Customer::factory()->create(['currency' => 'KWD']);
        $theirs = Invoice::factory()->create(['customer_id' => $stranger->getKey(), 'currency' => 'KWD', 'status' => InvoiceStatus::Open, 'subtotal_minor' => 100, 'total_minor' => 100]);
        $this->withdraw($theirs)->assertNotFound();
    }

    #[Test]
    public function a_member_who_may_not_pay_may_not_withdraw(): void
    {
        // billing.pay, the permission a plan change needs: a technical
        // contact or a read-only member moves no plan and no money. Nor does
        // an administrator, who holds billing.view and not billing.pay - the
        // one role here that tells the two permissions apart: with only the
        // technical contact and the member (neither holds billing.view), the
        // route could have asked billing.view and this stayed green (MN14,
        // the re-audit after round seven).
        [$subscription, $large] = $this->vpsSubscriptionOnANode();
        $this->changePlan($subscription, (string) $large->getKey(), $this->priceOf($large))->assertOk();
        $invoice = $this->openInvoiceOf($subscription);

        $this->assertContains('billing.view', CustomerRole::Administrator->permissions());
        $this->assertNotContains('billing.pay', CustomerRole::Administrator->permissions());

        foreach ([CustomerRole::Technical, CustomerRole::Member, CustomerRole::Administrator] as $role) {
            $member = User::factory()->create();
            $this->customer->members()->create(['user_id' => $member->id, 'role' => $role, 'accepted_at' => now()]);

            $this->actingAs($member)->postJson('/api/v1/invoices/'.$invoice->getKey().'/withdraw-plan-change')->assertForbidden();
        }

        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()?->status);
        $this->assertSame((string) $large->getKey(), (string) $subscription->fresh()?->plan_id);
    }

    #[Test]
    public function another_open_invoice_of_the_subscription_is_not_withdrawn_in_the_changes_place(): void
    {
        /*
         * Only the invoice that bills the unpaid change withdraws it. Another
         * invoice open on the same subscription (an operator's, say) voided
         * instead would void a bill that is not the change's and put back
         * nothing - the change's own invoice still open, the plan unpaid.
         */
        [$subscription, $large] = $this->vpsSubscriptionOnANode();
        $this->changePlan($subscription, (string) $large->getKey(), $this->priceOf($large))->assertOk();
        $upgrade = $this->openInvoiceOf($subscription);

        /** @var Invoice $other */
        $other = Invoice::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => 500,
            'total_minor' => 500,
        ]);

        $this->actingAs($this->user)->getJson('/api/v1/invoices/'.$other->getKey())->assertOk()->assertJsonPath('data.plan_change_withdrawable', false);
        $this->withdraw($other)->assertStatus(409)->assertJsonPath('error.code', 'invoice.plan_change_not_withdrawable');

        $this->assertSame(InvoiceStatus::Open, $other->fresh()?->status);
        $this->assertSame(InvoiceStatus::Open, $upgrade->fresh()?->status);
        $this->assertSame((string) $large->getKey(), (string) $subscription->fresh()?->plan_id);
    }

    #[Test]
    public function the_change_of_a_subscription_that_has_ended_is_not_withdrawn(): void
    {
        /*
         * An ended subscription is not put back on a plan (the void's restore
         * does not move an ended one): withdrawing would void the invoice and
         * restore nothing. Its open invoices are the wind-up's to withdraw.
         * Reached in the moment between the end and the wind-up's void.
         */
        [$subscription, $large] = $this->vpsSubscriptionOnANode();
        $this->changePlan($subscription, (string) $large->getKey(), $this->priceOf($large))->assertOk();
        $invoice = $this->openInvoiceOf($subscription);

        $subscription->fresh()?->forceFill(['status' => SubscriptionStatus::Cancelled, 'ended_at' => now()])->save();

        $this->actingAs($this->user)->getJson('/api/v1/invoices/'.$invoice->getKey())->assertOk()->assertJsonPath('data.plan_change_withdrawable', false);
        $this->withdraw($invoice)->assertStatus(409)->assertJsonPath('error.code', 'invoice.plan_change_not_withdrawable');
        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()?->status);
    }

    // -----------------------------------------------------------------

    private function withdraw(Invoice $invoice): TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/v1/invoices/'.$invoice->getKey().'/withdraw-plan-change');
    }

    private function openInvoiceOf(Subscription $subscription): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->sole();

        return $invoice;
    }

    /**
     * @return list<string>
     */
    private function everyRefusalOn(Subscription $subscription): array
    {
        $options = $this->actingAs($this->user)->getJson('/api/v1/subscriptions/'.$subscription->getKey().'/plan-options')->assertOk()->json('data');

        return array_values(array_merge(...array_map(static fn (array $option): array => $option['refusals'] ?? [], $options)));
    }

    /**
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

    private function changePlan(Subscription $subscription, string $planId, string $priceId): TestResponse
    {
        return $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'change-'.uniqid())
            ->postJson('/api/v1/subscriptions/'.$subscription->getKey().'/plan', ['plan_id' => $planId, 'price_id' => $priceId]);
    }

    /**
     * @return array{0: Subscription, 1: Plan, 2: ComputeNode}
     */
    private function vpsSubscriptionOnANode(): array
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

        $node = ComputeNode::factory()->create(['cluster_id' => ComputeCluster::factory()->create()->getKey()]);
        VirtualMachine::factory()->onNode($node, 900)->forService($service)->create(['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40]);

        return [$subscription, $large, $node];
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
