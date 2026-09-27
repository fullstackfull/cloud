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
use Lynomia\Modules\Compute\Application\Actions\ReleaseNodeCapacity;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Application\Queries\PlanChangeDelivery;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Vps\Application\Services\MachineCommitment;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Billing\BillingApiTestCase;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;

/**
 * The hypervisor is never read under a lock on the money path (B2, the
 * verification of round seven D).
 *
 * The plan-change quote and the payment and settlement refusals ask the
 * capacity question from the machine as the hypervisor reports it
 * (MachineCommitment::whyTheGrowthWouldNotFit()). The read was made inside
 * the question, and the question is asked under row locks: a plan change
 * under the subscription's (and its orders' and invoices'), a wallet
 * payment under the invoice's, a settlement under the subscription's. A
 * Proxmox read waits up to its timeout, 30 seconds by default, holding them.
 * Now the machine is read before each transaction opens and the reading is
 * passed in; the options screen reads it once for every plan it offers.
 *
 * Every read of the machine is recorded with the transaction depth it was
 * made at, against the depth outside the request (RefreshDatabase's own).
 */
final class TheHypervisorIsNotReadUnderAMoneyPathLockTest extends BillingApiTestCase
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
    public function a_plan_change_its_payment_and_its_settlement_read_the_machine_outside_every_transaction(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $this->customer = $customer;
        $small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160], 9_000);
        $large = $this->plan('large', ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], 90_000);
        foreach (['m1', 'm2', 'm3', 'm4'] as $i => $slug) {
            $this->plan($slug, ['vcpu' => 3 + $i, 'memory_mib' => 6144 + 1024 * $i, 'disk_gib' => 160], 20_000 + $i);
        }
        $subscription = $this->paidSubscriptionOn($customer, $small);
        $this->builtMachineFor($subscription, $small);

        $reads = $this->recordEveryRead();

        $reads->at('options');
        $this->optionFor($user, $subscription, $large);
        $reads->at('change');
        $this->changePlan($user, $subscription, $large, 'b2-up-0000001')->assertOk();

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->id)->where('status', InvoiceStatus::Open->value)->sole();
        $ledger = app(WalletLedger::class);
        $ledger->credit(wallet: $ledger->walletFor($customer, 'KWD'), amount: Money::ofMinor($invoice->total_minor, 'KWD'), kind: WalletTransactionKind::Topup, description: 'b2');
        $reads->at('wallet');
        $this->actingAs($user)->withHeaders(['Idempotency-Key' => 'b2-wallet-1'])->postJson('/api/v1/invoices/'.$invoice->id.'/wallet-credit')->assertOk();
        $reads->at('settlement');
        app(ResizeOnPlanChangeSettlement::class)->handle(new InvoicePaid((string) $invoice->id, (string) $customer->id, null, (string) $subscription->id, CarbonImmutable::now()));
        $this->hypervisor->atTheMomentOfLook = null;

        // Each path read the machine, and none under a transaction it opened.
        $this->assertSame([], $reads->under(), 'The hypervisor was read under a lock: '.json_encode($reads->all()));
        foreach (['change', 'wallet', 'settlement'] as $path) {
            $this->assertGreaterThanOrEqual(1, $reads->count($path), $path.' asked nothing of the machine: '.json_encode($reads->all()));
        }
        // Read once for the whole options screen, not once per plan.
        $this->assertSame(1, $reads->count('options'), json_encode($reads->all()));
        $this->assertSame(ProvisioningJobStatus::Queued, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->sole()->status);
    }

    #[Test]
    public function a_reading_taken_before_a_growth_landed_asks_more_never_less(): void
    {
        /*
         * A reading older than the resize's own. The machine grew in between
         * (a resize that landed): the quote, asking from the older and
         * smaller reading, asks more of the node than the resize will - the
         * resize is never refused what the quote passed.
         */
        [$customer] = $this->accountWithOwner();
        $this->customer = $customer;
        $small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160], 9_000);
        $subscription = $this->paidSubscriptionOn($customer, $small);
        $machine = $this->builtMachineFor($subscription, $small);
        $delivery = app(PlanChangeDelivery::class);

        $stale = $delivery->whatTheMachineRuns($subscription);
        $this->hypervisor->fleet->resizeVm('pve-01', (string) $machine->provider_id, new ResizeVmRequest(memoryMib: 8192));
        $fresh = $delivery->whatTheMachineRuns($subscription);

        $this->assertSame([4096, 8192], [$stale?->memoryMib, $fresh?->memoryMib]);
        // Nothing left on the node beyond 6144 MiB above what is held.
        DB::table('compute_nodes')->where('id', $this->node->id)->update(['memory_mib' => 4096 + 6144, 'memory_headroom_percent' => 0]);
        $commitment = app(MachineCommitment::class);

        // 12288 MiB: 8192 above what is held, 4096 above what now runs.
        $this->assertNotNull($commitment->whyTheGrowthWouldNotFit($machine->refresh(), 2, 12288, 160, $stale), 'The stale reading asked less than the fresh one.');
        $this->assertNull($commitment->whyTheGrowthWouldNotFit($machine, 2, 12288, 160, $fresh));
    }

    #[Test]
    public function a_machine_shrunk_by_hand_after_the_reading_is_the_one_thing_the_resize_refuses_after_the_quote_passed(): void
    {
        /*
         * The one thing a reading older than the resize's can cost, stated at
         * MachineCommitment::whyTheGrowthWouldNotFit(): a machine made
         * smaller by hand at the hypervisor between the two reads asks the
         * resize for what it shrank by. The resize refuses that for capacity
         * - nothing is grown onto room the node does not have.
         */
        [$customer] = $this->accountWithOwner();
        $this->customer = $customer;
        $small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 8192, 'disk_gib' => 160], 9_000);
        $subscription = $this->paidSubscriptionOn($customer, $small);
        $machine = $this->builtMachineFor($subscription, $small);
        $held = NodeCapacityReservation::query()->whereNull('released_at')->sole();
        app(ReleaseNodeCapacity::class)->execute($this->node, $held->resources(), $held->storage_id, $held->reservation_key);
        // Others hold 80000 MiB of the node's 100000; it takes 85000 committed.
        DB::table('compute_nodes')->where('id', $this->node->id)->update(['memory_mib' => 100_000, 'memory_headroom_percent' => 0, 'allocated_memory_mib' => 80_000]);

        $reading = app(PlanChangeDelivery::class)->whatTheMachineRuns($subscription);
        $this->assertNull(app(MachineCommitment::class)->whyTheGrowthWouldNotFit($machine->refresh(), 2, 10240, 160, $reading));

        // Read at 8192, 2048 above it is asked and fits (82048). Shrunk by hand
        // to 2048 MiB before the resize runs, which then asks 8192 (88192).
        $this->hypervisor->fleet->resizeVm('pve-01', (string) $machine->provider_id, new ResizeVmRequest(memoryMib: 2048));
        $resize = ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id, 'customer_id' => $customer->id, 'kind' => ProvisioningJobKind::Resize,
            'provider' => 'fake', 'status' => ProvisioningJobStatus::Queued,
            'payload' => ['virtual_machine_id' => (string) $machine->id, 'vcpu' => 2, 'memory_mib' => 10240, 'disk_gib' => 160],
        ]);
        $this->runWorker($resize);

        $this->assertSame(FailureClass::Capacity, $resize->refresh()->failure_class, (string) $resize->last_error);
        $this->assertSame(2048, $this->hypervisor->fleet->getVm('pve-01', (string) $machine->provider_id)?->memoryMib, 'The machine was grown onto room the node does not have.');
    }

    /**
     * Records every read of a machine at the hypervisor, with the transaction
     * depth it was made at above the test's own.
     */
    private function recordEveryRead(): object
    {
        $reads = new class
        {
            public string $phase = '';

            /** @var list<array{string, int}> */
            public array $log = [];

            public function at(string $phase): void
            {
                $this->phase = $phase;
            }

            /** @return list<array{string, int}> */
            public function under(): array
            {
                return array_values(array_filter($this->log, static fn (array $r): bool => $r[1] > 0));
            }

            public function count(string $phase): int
            {
                return count(array_filter($this->log, static fn (array $r): bool => $r[0] === $phase));
            }

            /** @return list<array{string, int}> */
            public function all(): array
            {
                return $this->log;
            }
        };

        $base = DB::transactionLevel();
        $look = function () use ($reads, $base, &$look): void {
            $reads->log[] = [$reads->phase, DB::transactionLevel() - $base];
            $this->hypervisor->atTheMomentOfLook = $look;
        };
        $this->hypervisor->atTheMomentOfLook = $look;

        return $reads;
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
