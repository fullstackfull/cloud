<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
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
use Lynomia\Modules\Provisioning\Domain\Events\ServiceStatusChanged;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Actions\ReturnAnUpgradeTheEndPrevented;
use Lynomia\Modules\Subscriptions\Application\Listeners\EndTheSubscriptionWithItsService;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;

/**
 * A paid upgrade whose resize never grew the machine, and whose service then
 * ended, is returned to the wallet - once, whichever of the service's end,
 * the wind-up of its subscription, the close of its job or the job's own
 * failure comes first.
 *
 * The re-audit's final pass (R10-M): a 27.000 KWD upgrade paid from the
 * wallet was settled (`delivered_at` stamped), its resize stopped in review on
 * node capacity, and the service was terminated and the job closed. The
 * wind-up read `delivered_at` as delivered, the close moved no money, and the
 * 27.000 stayed with the platform, while the customer had been told it was
 * "held until the change is applied, or returned if it cannot be". A resize
 * that failed outright came to the same. Each test below failed on the base
 * for that reason; the retry that delivers is the guard on the other side.
 */
final class APaidChangeItsServiceEndedBeforeDeliveringIsReturnedTest extends BillingApiTestCase
{
    use DrivesVpsCreatesThroughTheOperatorPath;

    private const int PAID = 27_000;

    private Product $product;

    private User $owner;

    private Subscription $subscription;

    private VirtualMachine $machine;

    private Invoice $invoice;

    private PlanChange $change;

    private ProvisioningJob $resize;

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
    public function a_paid_resize_in_review_is_returned_when_its_service_is_terminated_and_the_close_after_returns_nothing(): void
    {
        $this->aPaidUpgradeSettled();
        $this->theRoomGoes();
        $this->runTheResizeUntilItStops();
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $this->resize->refresh()->status);
        $this->assertSame(100_000 - self::PAID, $this->wallet(), 'held while the service lives');

        $this->terminateTheService();

        $this->assertSame(100_000, $this->wallet(), 'the service ended with the change undelivered: the payment goes back');
        $this->assertReturnedOnce();

        // Closing the job afterwards returns nothing more.
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/close', ['evidence' => 'Service terminated; machine gone.'])
            ->assertOk();

        $this->assertSame(ProvisioningJobStatus::Cancelled, $this->resize->refresh()->status);
        $this->assertSame(100_000, $this->wallet());
        $this->assertReturnedOnce();
    }

    #[Test]
    public function closing_the_job_returns_it_when_the_subscription_has_not_been_wound_up_and_the_wind_up_after_returns_nothing(): void
    {
        $this->aPaidUpgradeSettled();
        $this->theRoomGoes();
        $this->runTheResizeUntilItStops();

        // The service ended and nothing heard it: the subscription is live.
        DB::table('services')->where('id', $this->machine->service_id)->update(['status' => ServiceStatus::Terminated->value]);
        $this->assertFalse($this->subscription->refresh()->status->isTerminal());
        $this->assertSame(100_000 - self::PAID, $this->wallet());

        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/close', ['evidence' => 'Service terminated; machine gone.'])
            ->assertOk();

        $this->assertSame(100_000, $this->wallet(), 'the close takes the last pointer off the list, so it returns the payment');
        $this->assertReturnedOnce();

        // The subscription is wound up afterwards: nothing more.
        app(EndTheSubscriptionWithItsService::class)->handle(new ServiceStatusChanged(
            serviceId: (string) $this->machine->service_id,
            orderId: null,
            from: ServiceStatus::Active,
            to: ServiceStatus::Terminated,
        ));

        $this->assertTrue($this->subscription->refresh()->status->isTerminal());
        $this->assertSame(100_000, $this->wallet());
        $this->assertReturnedOnce();
    }

    #[Test]
    public function a_resize_that_fails_outright_after_its_service_ended_is_returned_when_it_fails(): void
    {
        $this->aPaidUpgradeSettled();

        // The service ends while the resize is still queued: nothing is known yet.
        $this->terminateTheService();
        $this->assertSame(100_000 - self::PAID, $this->wallet(), 'a resize still queued may yet run');

        // The machine is gone from the hypervisor's books; the resize fails outright.
        DB::table('virtual_machines')->where('id', $this->machine->id)->update(['provider_id' => null]);
        $this->runTheResizeUntilItStops();
        $this->assertSame(ProvisioningJobStatus::Failed, $this->resize->refresh()->status);

        $this->assertSame(100_000, $this->wallet());
        $this->assertReturnedOnce();
    }

    #[Test]
    public function a_paid_resize_that_fails_outright_on_a_live_service_is_held_then_returned_when_the_service_ends(): void
    {
        $this->aPaidUpgradeSettled();
        DB::table('virtual_machines')->where('id', $this->machine->id)->update(['provider_id' => null]);
        $this->runTheResizeUntilItStops();
        $this->assertSame(ProvisioningJobStatus::Failed, $this->resize->refresh()->status);

        // Live: held for an operator (docs/runbooks/provisioning-stuck.md §6).
        $this->assertSame(100_000 - self::PAID, $this->wallet());
        $this->assertNull($this->change->refresh()->returned_at);

        $this->terminateTheService();

        $this->assertSame(100_000, $this->wallet());
        $this->assertReturnedOnce();
    }

    #[Test]
    public function what_an_operator_already_refunded_of_a_held_change_is_not_returned_again_when_the_service_ends(): void
    {
        $this->aPaidUpgradeSettled();
        DB::table('virtual_machines')->where('id', $this->machine->id)->update(['provider_id' => null]);
        $this->runTheResizeUntilItStops();

        /** @var Transaction $charge */
        $charge = Transaction::query()->where('invoice_id', $this->invoice->id)->where('provider', 'wallet')->sole();
        $this->actingAs($this->operator())
            ->postJson('/api/admin/transactions/'.$charge->id.'/refunds', ['amount_minor' => self::PAID, 'reason' => 'Paid resize failed; returned by hand.'])
            ->assertCreated();
        $this->assertSame(100_000, $this->wallet());

        $this->terminateTheService();

        $this->assertSame(100_000, $this->wallet(), 'what the invoice no longer holds is not returned twice');
        $this->assertSame(0, (int) DB::table('wallet_transactions')->where('invoice_id', $this->invoice->id)
            ->where('kind', WalletTransactionKind::Topup->value)->sum('amount_minor'));
    }

    #[Test]
    public function a_retry_that_delivers_is_not_returned_when_the_service_ends_afterwards(): void
    {
        $this->aPaidUpgradeSettled();
        $this->theRoomGoes();
        $this->runTheResizeUntilItStops();

        DB::table('compute_nodes')->where('id', $this->node->id)->update(['memory_mib' => 131072]);
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/retry', ['evidence' => 'Node grown; retrying the paid resize.'])
            ->assertOk();
        $this->runTheResizeUntilItStops();
        $this->assertSame(ProvisioningJobStatus::Succeeded, $this->resize->refresh()->status);
        $this->assertSame(16384, $this->machine->refresh()->memory_mib);

        $this->terminateTheService();

        $this->assertSame(100_000 - self::PAID, $this->wallet(), 'delivered, so kept');
        $this->assertNull($this->change->refresh()->returned_at);
        $this->assertSame(0, $this->returnNotices());
    }

    #[Test]
    public function the_close_and_the_wind_up_asked_in_either_order_return_it_once(): void
    {
        $this->aPaidUpgradeSettled();
        $this->theRoomGoes();
        $this->runTheResizeUntilItStops();
        DB::table('services')->where('id', $this->machine->service_id)->update(['status' => ServiceStatus::Terminated->value]);

        $return = app(ReturnAnUpgradeTheEndPrevented::class);

        // Asked again and again, from each side: the invoice's lock and what
        // it still holds decide, not the order.
        $first = DB::transaction(fn (): int => $return->execute((string) $this->invoice->id));
        $second = DB::transaction(fn (): int => $return->execute((string) $this->invoice->id));
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/close', ['evidence' => 'Service terminated; machine gone.'])
            ->assertOk();
        $third = DB::transaction(fn (): int => $return->execute((string) $this->invoice->id));

        $this->assertSame([self::PAID, 0, 0], [$first, $second, $third]);
        $this->assertSame(100_000, $this->wallet());
        $this->assertReturnedOnce();
    }

    private function assertReturnedOnce(): void
    {
        $this->assertSame(self::PAID, (int) DB::table('wallet_transactions')
            ->where('invoice_id', $this->invoice->id)
            ->where('kind', WalletTransactionKind::Topup->value)
            ->sum('amount_minor'), 'returned to the wallet against the invoice, once');

        $change = $this->change->refresh();
        $this->assertNotNull($change->returned_at);
        $this->assertNotNull($change->return_reason);

        $audits = AuditEntry::query()
            ->where('action', AuditAction::PlanChanged->value)
            ->where('context->reason', ReturnAnUpgradeTheEndPrevented::AUDIT_REASON)
            ->get();
        $this->assertCount(1, $audits);
        $this->assertSame(self::PAID, $audits->first()->context['returned_to_wallet_minor']);
        $this->assertSame((string) $this->invoice->id, $audits->first()->context['proration_invoice_id']);

        $this->assertSame(1, $this->returnNotices());
    }

    private function returnNotices(): int
    {
        return DB::table('notifications')
            ->where('customer_id', $this->customer->id)
            ->where('type', 'billing.plan_change_returned_at_the_end')
            ->count();
    }

    private function aPaidUpgradeSettled(): void
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

    private function theRoomGoes(): void
    {
        DB::table('compute_nodes')->where('id', $this->node->id)->update(['memory_mib' => 4096 + 1024, 'memory_headroom_percent' => 0]);
    }

    private function runTheResizeUntilItStops(): void
    {
        $stopped = [ProvisioningJobStatus::NeedsReview, ProvisioningJobStatus::Failed, ProvisioningJobStatus::Succeeded];

        for ($i = 0; $i < 10 && ! in_array($this->resize->refresh()->status, $stopped, true); $i++) {
            DB::table('provisioning_jobs')->where('id', $this->resize->id)->update(['next_attempt_at' => null]);
            $this->runWorker($this->resize);
        }
    }

    private function terminateTheService(): void
    {
        /** @var Service $service */
        $service = Service::query()->findOrFail($this->machine->service_id);
        app(TransitionService::class)->execute($service, ServiceStatus::Terminated);
        $this->assertTrue($this->subscription->refresh()->status->isTerminal());
    }

    private function wallet(): int
    {
        $ledger = app(WalletLedger::class);

        return $ledger->balance($ledger->walletFor($this->customer, 'KWD'))->minorUnits();
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::SuperAdmin->value]);

        return $user;
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
            'invoice_id' => $invoice->getKey(), 'kind' => InvoiceItemKind::Plan,
            'description' => 'Renewal', 'quantity' => 1,
            'unit_amount_minor' => $recurring, 'total_minor' => $recurring,
            'period_start' => $subscription->current_period_start, 'period_end' => $subscription->current_period_end,
            'subscription_id' => $subscription->getKey(),
        ]);

        return $subscription;
    }
}
