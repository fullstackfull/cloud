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
 * A plan change reads the machine before its locks (B2), and a resize can
 * complete between that reading and the subscription's lock (A9-1, the
 * re-audit after round eight).
 *
 * The upgrade's resize was queued, the customer asked to go back down, the
 * reading saw the small machine, and the resize then landed: no job was left
 * busy, so the quote passed, and it measured from the reading - no change of
 * shape. The downgrade was credited 27.000 KWD, no resize was queued, and the
 * machine stayed 8 vCPU / 16 GiB at the small plan's price.
 *
 * ApplyPlanChange now takes the machine's row before the reading and again
 * under its locks, after the quote; a row that moved means the reading is
 * older than a resize, and the machine is read again outside the locks.
 */
final class AResizeThatLandsAfterTheReadingIsNotCreditedAsNoChangeTest extends BillingApiTestCase
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
    public function a_downgrade_whose_reading_predates_the_upgrades_resize_queues_a_resize_back_down(): void
    {
        [$customer, $user, $small, $subscription, $machine, $upgradeResize] = $this->upgradedAndPaidNotYetResized();

        $landed = false;
        $this->whenTheSubscriptionIsFirstLocked(function () use (&$landed, $upgradeResize): void {
            $landed = true;
            $this->runWorker($upgradeResize);
        });

        $down = $this->changePlan($user, $subscription, $small, 'r9a-down-stale-01')->assertOk();

        $this->assertTrue($landed, 'The upgrade\'s resize was not run between the reading and the lock.');
        $this->assertSame(ProvisioningJobStatus::Succeeded, $upgradeResize->fresh()?->status);
        $this->assertNotNull($down->json('data.resize'), 'The downgrade was credited and no resize was queued: the machine stays large at the small plan\'s price.');
        $this->assertSame(27_000, $this->walletOf($customer));

        /** @var ProvisioningJob $back */
        $back = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->where('idempotency_key', 'like', '%:change:%')->sole();
        $this->assertSame([2, 4096, 160], [$back->payload['vcpu'], $back->payload['memory_mib'], $back->payload['disk_gib']]);

        $this->runWorker($back);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $back->fresh()?->status, (string) $back->fresh()?->last_error);
        $this->assertSame([2, 4096, 160], [$machine->fresh()?->vcpu, $machine->fresh()?->memory_mib, $machine->fresh()?->disk_gib]);
    }

    #[Test]
    public function a_resize_that_lands_just_after_the_hypervisor_answered_is_not_missed_by_the_row_taken_with_the_reading(): void
    {
        /*
         * The row is taken before the reading, not after it: a resize that
         * lands between the hypervisor's answer and a row taken after it
         * would leave that row and the one under the lock agreeing, and the
         * stale reading measured from.
         */
        [$customer, $user, $small, $subscription, $machine, $upgradeResize] = $this->upgradedAndPaidNotYetResized();

        $answered = false;
        $landed = false;
        $this->hypervisor->atTheMomentOfLook = static function () use (&$answered): void {
            $answered = true;
        };
        DB::connection()->beforeExecuting(function () use (&$answered, &$landed, $upgradeResize): void {
            if ($answered && ! $landed) {
                $landed = true;
                $this->runWorker($upgradeResize);
            }
        });

        $down = $this->changePlan($user, $subscription, $small, 'r9a-down-stale-03')->assertOk();

        $this->assertTrue($landed, 'The upgrade\'s resize was not run after the hypervisor answered.');
        $this->assertSame(ProvisioningJobStatus::Succeeded, $upgradeResize->fresh()?->status);
        $this->assertNotNull($down->json('data.resize'), 'The downgrade was credited and no resize was queued: the machine stays large at the small plan\'s price.');
        $this->assertSame(27_000, $this->walletOf($customer));
        $this->assertSame([8, 16384, 160], [$machine->fresh()?->vcpu, $machine->fresh()?->memory_mib, $machine->fresh()?->disk_gib]);
    }

    #[Test]
    public function a_machine_whose_row_moves_under_every_reading_is_refused_as_busy_and_no_money_moves(): void
    {
        [$customer, $user, $small, $subscription, $machine, $upgradeResize] = $this->upgradedAndPaidNotYetResized();
        $this->runWorker($upgradeResize);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $upgradeResize->fresh()?->status);
        $this->assertSame(8, $machine->fresh()?->vcpu);

        // Every lock finds the row moved since the reading taken before it,
        // with no job left busy to refuse the change for it.
        DB::connection()->beforeExecuting(static function (string $sql) use ($machine): void {
            if (str_contains($sql, 'from "subscriptions"') && str_contains($sql, 'for update')) {
                DB::table('virtual_machines')->where('id', $machine->id)->increment('vcpu');
            }
        });

        $refused = $this->changePlan($user, $subscription, $small, 'r9a-down-stale-02');

        $refused->assertStatus(409);
        $this->assertSame('service_busy', $refused->json('error.details.refusals'));
        $this->assertSame(0, $this->walletOf($customer));
        $this->assertNotSame((string) $small->id, (string) $subscription->fresh()?->plan_id);
        $this->assertSame(3, $machine->fresh()?->vcpu - 8, 'Not read three times before refusing.');
    }

    /**
     * @return array{0: Customer, 1: User, 2: Plan, 3: Subscription, 4: VirtualMachine, 5: ProvisioningJob}
     */
    private function upgradedAndPaidNotYetResized(): array
    {
        [$customer, $user] = $this->accountWithOwner();
        $this->customer = $customer;
        $small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160], 9_000);
        $large = $this->plan('large', ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], 90_000);
        $subscription = $this->paidSubscriptionOn($customer, $small);
        $machine = $this->builtMachineFor($subscription, $small);

        $this->changePlan($user, $subscription, $large, 'r9a-up-000001')->assertOk();

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->id)->where('status', InvoiceStatus::Open->value)->sole();
        $capture = Transaction::factory()->forCustomer($customer)->create(['amount_minor' => $invoice->total_minor, 'currency' => $invoice->currency]);
        app(SettleInvoice::class)->execute($invoice, $capture);
        app(ResizeOnPlanChangeSettlement::class)->handle(new InvoicePaid((string) $invoice->id, (string) $customer->id, null, (string) $subscription->id, CarbonImmutable::now()));

        /** @var ProvisioningJob $resize */
        $resize = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->sole();
        $this->assertSame(ProvisioningJobStatus::Queued, $resize->status);
        $this->assertSame([2, 4096, 160], [$machine->fresh()?->vcpu, $machine->fresh()?->memory_mib, $machine->fresh()?->disk_gib]);

        return [$customer, $user, $small, $subscription, $machine, $resize];
    }

    private function whenTheSubscriptionIsFirstLocked(\Closure $then): void
    {
        $fired = false;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$fired, $then): void {
            if (! $fired && str_contains($sql, 'from "subscriptions"') && str_contains($sql, 'for update')) {
                $fired = true;
                $then();
            }
        });
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
