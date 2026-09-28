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
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
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
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Billing\BillingApiTestCase;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;

/**
 * A paid upgrade the hypervisor made and could not confirm is not followed
 * by a downgrade credited as no change of shape (A8-1, the re-audit after
 * round seven).
 *
 * The upgrade 2 / 4096 / 40 -> 8 / 16384 / 160 reached the hypervisor and the
 * machine could not be read back (`vps.resize_unverified`). The handler
 * settled the commitment and did not write the row - nothing had confirmed
 * the shape - and failed the job, permanently. A failed job does not hold a
 * service's plan changes, so the downgrade back to 2 / 4096 / 40 was quoted
 * from the row as no change of shape: no refusal, no resize, 27.000 KWD
 * credited to the wallet and the subscription billed 9.000 - while the
 * machine stayed 8 / 16384 / 160.
 *
 * The unverified resize now stops in review, which holds every change of
 * plan (ServiceBusy) until a person settles it; the operator's retry looks
 * at the machine and writes the row, after which the downgrade is measured
 * from the machine it has - here refused, for the disk it would shrink.
 */
final class AnUnverifiedResizeIsNotCreditedAsAChangeItDidNotMakeTest extends BillingApiTestCase
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
    public function a_downgrade_after_an_unverified_upgrade_is_refused_and_credits_nothing(): void
    {
        [$customer, $user, $subscription, $machine, $small, $large, $resize] = $this->anUnverifiedUpgrade();

        $this->assertSame('vps.resize_unverified', $resize->result['error']['code'] ?? null);
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $resize->status, 'An unverified resize left nothing holding the service.');
        $this->assertSame(FailureClass::Timeout, $resize->failure_class);
        // The row is as it was; the machine is the large shape.
        $this->assertSame([2, 4096, 40], [$machine->refresh()->vcpu, $machine->memory_mib, $machine->disk_gib]);
        $live = $this->hypervisor->fleet->getVm('pve-01', (string) $machine->provider_id);
        $this->assertSame([8, 16384, 160], [$live?->vcpu, $live?->memoryMib, $live?->diskGib]);

        $option = $this->optionFor($user, $subscription, $small);
        $this->assertContains('service_busy', $option['refusals'] ?? [], 'The downgrade was offered: '.json_encode($option));

        $wallet = $this->walletOf($customer);
        $down = $this->changePlan($user, $subscription, $small, 'a8-1-down-000001');

        $this->assertNotSame(200, $down->status(), 'A downgrade the machine did not make was accepted.');
        $this->assertSame($wallet, $this->walletOf($customer), 'A change of shape the machine did not make was credited.');
        $this->assertSame((string) $large->id, (string) $subscription->fresh()?->plan_id);
        $this->assertSame(90_000, $subscription->fresh()?->recurring_amount_minor);
        $this->assertSame(1, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count());
    }

    #[Test]
    public function the_operators_retry_writes_the_row_and_the_downgrade_is_measured_from_the_machine(): void
    {
        [$customer, $user, $subscription, $machine, $small, , $resize] = $this->anUnverifiedUpgrade();

        // The retry the review list offers: it looks, and settles to what it sees.
        $this->retryAsOperator($resize)->assertOk();
        DB::table('provisioning_jobs')->where('id', $resize->id)->update(['next_attempt_at' => null]);
        $this->runWorker($resize);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->refresh()->status, (string) $resize->last_error);
        $this->assertSame([8, 16384, 160], [$machine->refresh()->vcpu, $machine->memory_mib, $machine->disk_gib]);
        $live = NodeCapacityReservation::query()->whereNull('released_at')->sole();
        $this->assertSame([8, 16384, 160], [(int) $live->vcpu, (int) $live->memory_mib, (int) $live->disk_gib]);

        // Now the downgrade is a change of shape, and one that would shrink the disk.
        $option = $this->optionFor($user, $subscription, $small);
        $this->assertTrue($option['changes_infrastructure'] ?? null);
        $this->assertContains('would_shrink_disk', $option['refusals'] ?? []);

        $wallet = $this->walletOf($customer);
        $this->assertNotSame(200, $this->changePlan($user, $subscription, $small, 'a8-1-down-000002')->status());
        $this->assertSame($wallet, $this->walletOf($customer));
    }

    #[Test]
    public function an_unverified_resize_cannot_be_adopted_so_it_is_not_settled_without_the_row(): void
    {
        /*
         * B-1 (the verification of round eight A): the operator's adoption,
         * given any reference, settled the resize in review as succeeded
         * with no row written; the service was no longer busy and the
         * downgrade was credited 27.000 from the stale row.
         */
        [$customer, $user, $subscription, $machine, $small, $large, $resize] = $this->anUnverifiedUpgrade();

        foreach (['resize-of-'.$machine->provider_id, (string) $machine->provider_id] as $reference) {
            $this->actingAs($this->operator())
                ->postJson('/api/admin/provisioning/jobs/'.$resize->id.'/adopt', ['provider_reference' => $reference, 'evidence' => 'Looked at the machine at the node.'])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'provisioning.adoption_not_a_build');
        }

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $resize->refresh()->status);

        $wallet = $this->walletOf($customer);
        $this->assertNotSame(200, $this->changePlan($user, $subscription, $small, 'a8-1-down-000003')->status());
        $this->assertSame($wallet, $this->walletOf($customer), 'A change of shape the machine did not make was credited.');
        $this->assertSame((string) $large->id, (string) $subscription->fresh()?->plan_id);
    }

    #[Test]
    public function a_row_left_behind_the_machine_is_not_measured_from_when_the_machine_is_read(): void
    {
        /*
         * Belt and braces: however a row came to be behind its machine, a
         * quote holding a reading of the machine measures the change of
         * shape from the reading. Here the job is made to stop holding the
         * service by hand - the state no code path now leaves - and the
         * downgrade is still a change of shape that would shrink the disk.
         */
        [$customer, $user, $subscription, , $small] = $this->anUnverifiedUpgrade();
        ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->update(['status' => ProvisioningJobStatus::Failed->value]);

        $option = $this->optionFor($user, $subscription, $small);
        $this->assertTrue($option['changes_infrastructure'] ?? null, json_encode($option));
        $this->assertSame(['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], $option['current_resources'] ?? null);
        $this->assertContains('would_shrink_disk', $option['refusals'] ?? []);

        $wallet = $this->walletOf($customer);
        $this->assertNotSame(200, $this->changePlan($user, $subscription, $small, 'a8-1-down-000004')->status());
        $this->assertSame($wallet, $this->walletOf($customer));
    }

    #[Test]
    public function a_disk_the_reading_puts_below_the_row_is_measured_from_the_row(): void
    {
        /*
         * Measured from the reading, the disk is the larger of the reading's
         * and the row's: the resize refuses a disk below the row, and a quote
         * that passes is a change the resize does not refuse. The row says
         * 160, the hypervisor 40; a plan of 100 is refused as a shrink here
         * rather than sold and then refused by the resize.
         */
        [$customer, $user] = $this->accountWithOwner();
        $this->customer = $customer;
        $small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40], 9_000);
        $mid = $this->plan('mid', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 100], 20_000);
        $subscription = $this->paidSubscriptionOn($customer, $small);
        $machine = $this->builtMachineFor($subscription, $small);
        $machine->forceFill(['disk_gib' => 160])->save();

        $option = $this->optionFor($user, $subscription, $mid);
        $this->assertContains('would_shrink_disk', $option['refusals'] ?? [], json_encode($option));
    }

    /**
     * @return array{Customer, User, Subscription, VirtualMachine, Plan, Plan, ProvisioningJob}
     */
    private function anUnverifiedUpgrade(): array
    {
        [$customer, $user] = $this->accountWithOwner();
        $this->customer = $customer;
        $small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40], 9_000);
        $large = $this->plan('large', ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], 90_000);
        $subscription = $this->paidSubscriptionOn($customer, $small);
        $machine = $this->builtMachineFor($subscription, $small);

        $this->changePlan($user, $subscription, $large, 'a8-1-up-00000001')->assertOk();
        /** @var Invoice $up */
        $up = Invoice::query()->where('subscription_id', $subscription->id)->where('status', InvoiceStatus::Open->value)->sole();
        $capture = Transaction::factory()->forCustomer($customer)->create(['amount_minor' => $up->total_minor, 'currency' => $up->currency]);
        app(SettleInvoice::class)->execute($up, $capture);
        app(ResizeOnPlanChangeSettlement::class)->handle(new InvoicePaid((string) $up->id, (string) $customer->id, null, (string) $subscription->id, CarbonImmutable::now()));

        /** @var ProvisioningJob $resize */
        $resize = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->sole();

        // The resize lands, and the machine cannot be read back after it.
        $this->hypervisor->afterAResize = function (): void {
            $this->hypervisor->failReadsWith = ComputeProviderException::requestFailed('fake', 'get_vm', ['provider_message' => 'timeout']);
        };
        $this->runWorker($resize);
        $this->hypervisor->failReadsWith = null;

        return [$customer, $user, $subscription, $machine, $small, $large, $resize->refresh()];
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
