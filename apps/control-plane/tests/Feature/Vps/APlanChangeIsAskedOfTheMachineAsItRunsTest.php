<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Billing\BillingApiTestCase;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;

/**
 * A plan change is quoted, and delivered, from the machine as it runs - not
 * from the shape the service was bought at (F-07, the VPS half, round seven).
 *
 * `services.resources` is written when the service is bought and never by a
 * resize, and the quote and the delivery check read it as "what the machine
 * is now". After one resize it was wrong, and every question asked of it
 * was wrong with it:
 *
 *  - an upgrade back to the shape the service was bought at read as no change
 *    of shape: the capacity question was never asked, the upgrade was sold on
 *    a node that could not hold it, paid, and stopped in review with the
 *    money held;
 *  - a downgrade after a delivered upgrade read as no change of shape either:
 *    the wallet was credited, the subscription billed at the small plan, and
 *    no resize queued - the machine stayed large (N1, the platform's money);
 *  - a downgrade onto a smaller disk than the machine had grown to got past
 *    the disk-shrink refusal, and was billed before the resize refused it.
 *
 * The machine's row (virtual_machines, the shape the hypervisor confirmed and
 * the one the resize itself reads) is now what both ask. And a pure shrink is
 * never refused for capacity, even of a machine whose commitment the node's
 * ledger does not hold (N3).
 */
final class APlanChangeIsAskedOfTheMachineAsItRunsTest extends BillingApiTestCase
{
    use DrivesVpsCreatesThroughTheOperatorPath;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->setUpTheCluster();
        $this->hypervisor->loseTheAnswerToCreates = false;
        $this->travelTo(CarbonImmutable::parse('2026-04-21 00:00:00', 'UTC'));
        $this->freezeTime();
        $this->product = Product::factory()->create(['kind' => 'vps']);
    }

    #[Test]
    public function an_upgrade_back_to_the_bought_shape_on_a_node_that_filled_is_refused_before_money_moves(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $this->customer = $customer;
        $small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160], 9_000);
        $large = $this->plan('large', ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], 90_000);
        $subscription = $this->paidSubscriptionOn($customer, $large);
        $machine = $this->builtMachineFor($subscription, $large);

        $this->changePlan($user, $subscription, $small, 'r7a-down-00001')->assertOk();
        $this->runWorker(ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->sole());
        $this->assertSame([2, 4096], [$machine->fresh()?->vcpu, $machine->fresh()?->memory_mib]);

        // Somebody else fills the node's memory.
        DB::table('compute_nodes')->where('id', $this->node->id)->update(['allocated_memory_mib' => 131072 - 4096]);

        $option = $this->optionFor($user, $subscription, $large);
        $this->assertContains('not_deliverable', $option['refusals'], 'An upgrade the node cannot hold was offered: the quote read the shape the service was bought at.');
        $this->assertTrue($option['changes_infrastructure']);
        $this->assertSame(['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160], $option['current_resources']);

        $this->changePlan($user, $subscription, $large, 'r7a-up-000002')
            ->assertStatus(409)
            ->assertJsonPath('error.details.refusals', 'not_deliverable');

        $this->assertSame(0, Invoice::query()->where('subscription_id', $subscription->id)->where('status', InvoiceStatus::Open->value)->count(), 'An upgrade nothing can deliver was invoiced.');
        $this->assertSame((string) $small->id, (string) $subscription->fresh()?->plan_id);
        $this->assertSame(9_000, $subscription->fresh()?->recurring_amount_minor);
    }

    #[Test]
    public function an_upgrade_back_to_the_bought_shape_accepted_with_room_is_not_payable_once_the_node_fills(): void
    {
        /*
         * The payment asks the change's question again (PlanChangeDelivery::
         * refusalForTheChange()), measured from what the service ran before
         * the change. Read from the shape the service was bought at - the
         * target itself - the growth read as none, and the payment was taken
         * for a resize the node then refused.
         */
        [$customer, $user] = $this->accountWithOwner();
        $this->customer = $customer;
        $small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160], 9_000);
        $large = $this->plan('large', ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], 90_000);
        $subscription = $this->paidSubscriptionOn($customer, $large);
        $this->builtMachineFor($subscription, $large);

        $this->changePlan($user, $subscription, $small, 'r7a-down-00003')->assertOk();
        $this->runWorker(ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->sole());

        $this->changePlan($user, $subscription, $large, 'r7a-up-000003')->assertOk();
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->id)->where('status', InvoiceStatus::Open->value)->sole();

        DB::table('compute_nodes')->where('id', $this->node->id)->update(['allocated_memory_mib' => 131072 - 4096]);

        $this->actingAs($user)->getJson('/api/v1/invoices/'.$invoice->id)->assertOk()->assertJsonPath('data.is_payable', false);
        $this->actingAs($user)->postJson('/api/v1/invoices/'.$invoice->id.'/payments')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invoice.plan_change_not_deliverable');
    }

    #[Test]
    public function a_downgrade_after_a_delivered_upgrade_resizes_the_machine(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $this->customer = $customer;
        $small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160], 9_000);
        $large = $this->plan('large', ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], 90_000);
        $subscription = $this->paidSubscriptionOn($customer, $small);
        $machine = $this->builtMachineFor($subscription, $small);

        $this->upgradeAndDeliver($user, $customer, $subscription, $large);
        $this->assertSame([8, 16384, 160], [$machine->fresh()?->vcpu, $machine->fresh()?->memory_mib, $machine->fresh()?->disk_gib]);

        $option = $this->optionFor($user, $subscription, $small);
        $this->assertSame(['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], $option['current_resources']);
        $this->assertTrue($option['changes_infrastructure'], 'A downgrade of a grown machine read as no change of shape.');

        $down = $this->changePlan($user, $subscription, $small, 'r7a-down-00002')->assertOk();
        $this->assertNotNull($down->json('data.resize'), 'The downgrade was credited and no resize was queued.');

        /** @var ProvisioningJob $resize */
        $resize = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->where('idempotency_key', 'like', '%:change:%')->sole();
        $this->assertSame(2, $resize->payload['vcpu']);
        $this->runWorker($resize);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->fresh()?->status, (string) $resize->fresh()?->last_error);
        $this->assertSame([2, 4096, 160], [$machine->fresh()?->vcpu, $machine->fresh()?->memory_mib, $machine->fresh()?->disk_gib], 'The machine kept the upgrade\'s shape at the small plan\'s price.');
    }

    #[Test]
    public function a_downgrade_onto_a_smaller_disk_than_the_machine_grew_to_is_refused_before_money_moves(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $this->customer = $customer;
        $small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40], 9_000);
        $mid = $this->plan('mid', ['vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 80], 30_000);
        $large = $this->plan('large', ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], 90_000);
        $subscription = $this->paidSubscriptionOn($customer, $small);
        $this->builtMachineFor($subscription, $small);
        $this->upgradeAndDeliver($user, $customer, $subscription, $large);

        $walletBefore = $this->walletOf($customer);

        foreach ([$mid, $small] as $target) {
            $this->assertContains('would_shrink_disk', $this->optionFor($user, $subscription, $target)['refusals']);
            $this->changePlan($user, $subscription, $target, 'r7a-shrink-'.$target->slug)
                ->assertStatus(409)
                ->assertJsonPath('error.details.refusals', 'would_shrink_disk');
        }

        $this->assertSame($walletBefore, $this->walletOf($customer), 'A downgrade the resize must refuse was credited.');
        $this->assertSame((string) $large->id, (string) $subscription->fresh()?->plan_id);
        $this->assertSame(90_000, $subscription->fresh()?->recurring_amount_minor);
        $this->assertSame(0, ProvisioningJob::query()->where('idempotency_key', 'like', '%:change:%')->count());
    }

    #[Test]
    public function a_pure_shrink_of_a_machine_the_node_ledger_does_not_hold_is_not_refused_for_capacity(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $this->customer = $customer;
        $small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160], 9_000);
        $large = $this->plan('large', ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], 90_000);
        $subscription = $this->paidSubscriptionOn($customer, $large);
        $machine = $this->builtMachineFor($subscription, $large);

        // Its commitment given back (an adoption from before adoptions
        // committed, say), and the node filled to its ceilings by others.
        NodeCapacityReservation::query()->update(['released_at' => now()]);
        DB::table('compute_nodes')->where('id', $this->node->id)->update([
            'allocated_cpu_cores' => 1000, 'allocated_memory_mib' => 1_000_000, 'allocated_storage_gib' => 100_000,
        ]);
        $this->assertSame(0, NodeCapacityReservation::query()->whereNull('released_at')->count());

        $this->assertSame([], $this->optionFor($user, $subscription, $small)['refusals'], 'A shrink was refused for capacity.');
        $this->changePlan($user, $subscription, $small, 'r7a-shrink-full')->assertOk();

        /** @var ProvisioningJob $resize */
        $resize = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->sole();
        $this->runWorker($resize);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->fresh()?->status, (string) $resize->fresh()?->last_error);
        $this->assertSame([2, 4096], [$machine->fresh()?->vcpu, $machine->fresh()?->memory_mib]);
    }

    // -----------------------------------------------------------------

    private function upgradeAndDeliver(User $user, Customer $customer, Subscription $subscription, Plan $plan): void
    {
        $this->changePlan($user, $subscription, $plan, 'r7a-up-'.$plan->slug)->assertOk();

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->id)->where('status', InvoiceStatus::Open->value)->sole();
        $capture = Transaction::factory()->forCustomer($customer)->create(['amount_minor' => $invoice->total_minor, 'currency' => $invoice->currency]);
        app(SettleInvoice::class)->execute($invoice, $capture);
        app(ResizeOnPlanChangeSettlement::class)->handle(new InvoicePaid((string) $invoice->id, (string) $customer->id, null, (string) $subscription->id, CarbonImmutable::now()));

        /** @var ProvisioningJob $resize */
        $resize = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->where('idempotency_key', 'like', '%:invoice:'.$invoice->id)->sole();
        $this->runWorker($resize);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->fresh()?->status, (string) $resize->fresh()?->last_error);
    }

    private function builtMachineFor(Subscription $subscription, Plan $plan): VirtualMachine
    {
        $job = $this->createJob(['vcpu' => $plan->resources['vcpu'], 'memory_mib' => $plan->resources['memory_mib'], 'disk_gib' => $plan->resources['disk_gib']]);
        $this->runWorker($job);

        /** @var Service $service */
        $service = Service::query()->findOrFail($job->service_id);
        $service->forceFill(['subscription_id' => $subscription->id, 'resources' => $plan->resources, 'plan_id' => $plan->id, 'status' => ServiceStatus::Active])->save();

        return VirtualMachine::query()->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function optionFor(User $user, Subscription $subscription, Plan $plan): array
    {
        $options = $this->actingAs($user)->getJson("/api/v1/subscriptions/{$subscription->id}/plan-options")->assertOk()->json('data');

        return collect($options)->firstWhere('plan_id', (string) $plan->id);
    }

    private function changePlan(User $user, Subscription $subscription, Plan $plan, string $key): TestResponse
    {
        return $this->actingAs($user)->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", ['plan_id' => $plan->id, 'price_id' => $this->priceOf($plan)->id]);
    }

    private function walletOf(Customer $customer): int
    {
        $ledger = app(WalletLedger::class);

        return $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits();
    }

    /**
     * @param  array<string, int>  $resources
     */
    private function plan(string $slug, array $resources, int $minor): Plan
    {
        $plan = Plan::factory()->create(['product_id' => $this->product->getKey(), 'slug' => $slug, 'resources' => $resources, 'is_active' => true, 'is_public' => true]);
        PlanPrice::factory()->create(['plan_id' => $plan->getKey(), 'currency' => 'KWD', 'billing_period' => BillingPeriod::Monthly, 'recurring_amount_minor' => $minor, 'setup_amount_minor' => 0, 'is_active' => true]);

        return $plan;
    }

    private function priceOf(Plan $plan): PlanPrice
    {
        return PlanPrice::query()->where('plan_id', $plan->getKey())->sole();
    }

    private function paidSubscriptionOn(Customer $customer, Plan $plan): Subscription
    {
        $recurring = $this->priceOf($plan)->recurring_amount_minor;
        $subscription = Subscription::factory()->startingOn(CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC'))->create([
            'customer_id' => $customer->getKey(), 'plan_id' => $plan->getKey(), 'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly, 'recurring_amount_minor' => $recurring,
        ]);
        $invoice = Invoice::factory()->paid()->create([
            'customer_id' => $customer->getKey(), 'subscription_id' => $subscription->getKey(),
            'subtotal_minor' => $recurring, 'total_minor' => $recurring, 'amount_paid_minor' => $recurring,
        ]);
        Transaction::factory()->create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'amount_minor' => $recurring, 'currency' => 'KWD']);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->getKey(), 'kind' => InvoiceItemKind::Plan, 'description' => 'Renewal', 'quantity' => 1,
            'unit_amount_minor' => $recurring, 'total_minor' => $recurring,
            'period_start' => $subscription->current_period_start, 'period_end' => $subscription->current_period_end,
            'subscription_id' => $subscription->getKey(),
        ]);

        return $subscription;
    }
}
