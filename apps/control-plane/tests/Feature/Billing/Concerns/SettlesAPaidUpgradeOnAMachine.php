<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Concerns;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Application\Actions\TransitionService;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Tests\Feature\Billing\BillingApiTestCase;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;

/**
 * A 27.000 KWD upgrade (small 9.000 -> large 90.000, monthly, paid from a
 * 100.000 wallet on day 21 of a 30-day period) settled while the
 * subscription was live, on a machine built through the operator path - the
 * setting of the round ten M oracles and of the verifier's probes.
 *
 * @mixin BillingApiTestCase
 */
trait SettlesAPaidUpgradeOnAMachine
{
    use DrivesVpsCreatesThroughTheOperatorPath;

    protected const int PAID = 27_000;

    protected Product $product;

    protected User $owner;

    protected Subscription $subscription;

    protected VirtualMachine $machine;

    protected Invoice $invoice;

    protected PlanChange $change;

    protected ProvisioningJob $resize;

    protected function setUpThePaidUpgradeWorld(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->setUpTheCluster();
        $this->hypervisor->loseTheAnswerToCreates = false;
        $this->travelTo(CarbonImmutable::parse('2026-04-21 00:00:00', 'UTC'));
        $this->freezeTime();
        $this->product = Product::factory()->create(['kind' => 'vps']);
    }

    protected function returnNotices(): int
    {
        return DB::table('notifications')
            ->where('customer_id', $this->customer->id)
            ->where('type', 'billing.plan_change_returned_at_the_end')
            ->count();
    }

    protected function aPaidUpgradeSettled(): void
    {
        [$customer, $this->owner] = $this->accountWithOwner();
        $this->customer = $customer;
        $small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40], 9_000);
        $large = $this->plan('large', ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 40], 90_000);
        $this->subscription = $this->paidSubscriptionOn($customer, $small);
        $this->machine = $this->builtMachineFor($this->subscription, $small);

        $ledger = app(WalletLedger::class);
        $ledger->credit(wallet: $ledger->walletFor($customer, 'KWD'), amount: Money::ofMinor(100_000, 'KWD'), kind: WalletTransactionKind::Topup, description: 'test top-up');

        $this->actingAs($this->owner)->withHeader('Idempotency-Key', 'r10m-up-1')
            ->postJson("/api/v1/subscriptions/{$this->subscription->id}/plan", ['plan_id' => $large->id, 'price_id' => $this->priceOf($large)->id])
            ->assertOk();
        $this->invoice = Invoice::query()->where('subscription_id', $this->subscription->id)->where('status', InvoiceStatus::Open->value)->sole();
        $this->assertSame(self::PAID, (int) $this->invoice->total_minor);
        $this->actingAs($this->owner)->withHeaders(['Idempotency-Key' => 'r10m-wallet-1'])
            ->postJson('/api/v1/invoices/'.$this->invoice->id.'/wallet-credit')
            ->assertOk();
        app(ResizeOnPlanChangeSettlement::class)->handle(new InvoicePaid((string) $this->invoice->id, (string) $customer->id, null, (string) $this->subscription->id, CarbonImmutable::now()));

        $this->change = PlanChange::query()->where('proration_invoice_id', $this->invoice->id)->sole();
        $this->assertNotNull($this->change->delivered_at, 'the settlement was heard while the subscription was live');
        $this->resize = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->sole();
        $this->assertSame(100_000 - self::PAID, $this->wallet());
    }

    protected function theRoomGoes(): void
    {
        DB::table('compute_nodes')->where('id', $this->node->id)->update(['memory_mib' => 4096 + 1024, 'memory_headroom_percent' => 0]);
    }

    protected function runTheResizeUntilItStops(): void
    {
        $stopped = [ProvisioningJobStatus::NeedsReview, ProvisioningJobStatus::Failed, ProvisioningJobStatus::Succeeded];

        for ($i = 0; $i < 10 && ! in_array($this->resize->refresh()->status, $stopped, true); $i++) {
            DB::table('provisioning_jobs')->where('id', $this->resize->id)->update(['next_attempt_at' => null]);
            $this->runWorker($this->resize);
        }
    }

    protected function terminateTheService(): void
    {
        /** @var Service $service */
        $service = Service::query()->findOrFail($this->machine->service_id);
        app(TransitionService::class)->execute($service, ServiceStatus::Terminated);
        $this->assertTrue($this->subscription->refresh()->status->isTerminal());
    }

    protected function wallet(): int
    {
        $ledger = app(WalletLedger::class);

        return $ledger->balance($ledger->walletFor($this->customer, 'KWD'))->minorUnits();
    }

    protected function operator(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::SuperAdmin->value]);

        return $user;
    }

    protected function builtMachineFor(Subscription $subscription, Plan $plan): VirtualMachine
    {
        $job = $this->createJob(['vcpu' => $plan->resources['vcpu'], 'memory_mib' => $plan->resources['memory_mib'], 'disk_gib' => $plan->resources['disk_gib']]);
        $this->runWorker($job);
        /** @var Service $service */
        $service = Service::query()->findOrFail($job->service_id);
        $service->forceFill(['subscription_id' => $subscription->id, 'resources' => $plan->resources, 'plan_id' => $plan->id, 'status' => ServiceStatus::Active])->save();

        return VirtualMachine::query()->sole();
    }

    /**
     * @param  array<string, int>  $resources
     */
    protected function plan(string $slug, array $resources, int $minor): Plan
    {
        $plan = Plan::factory()->create(['product_id' => $this->product->getKey(), 'slug' => $slug, 'resources' => $resources, 'is_active' => true, 'is_public' => true]);
        PlanPrice::factory()->create(['plan_id' => $plan->getKey(), 'currency' => 'KWD', 'billing_period' => BillingPeriod::Monthly, 'recurring_amount_minor' => $minor, 'setup_amount_minor' => 0, 'is_active' => true]);

        return $plan;
    }

    protected function priceOf(Plan $plan): PlanPrice
    {
        return PlanPrice::query()->where('plan_id', $plan->getKey())->sole();
    }

    protected function paidSubscriptionOn(Customer $customer, Plan $plan): Subscription
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
            'invoice_id' => $invoice->getKey(), 'kind' => InvoiceItemKind::Plan,
            'description' => 'Renewal', 'quantity' => 1,
            'unit_amount_minor' => $recurring, 'total_minor' => $recurring,
            'period_start' => $subscription->current_period_start, 'period_end' => $subscription->current_period_end,
            'subscription_id' => $subscription->getKey(),
        ]);

        return $subscription;
    }
}
