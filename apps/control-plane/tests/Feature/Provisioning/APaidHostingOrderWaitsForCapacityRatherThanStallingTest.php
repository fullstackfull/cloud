<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Application\Actions\StartInvoicePayment;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuysSharedHosting;
use Tests\TestCase;

/**
 * Checkout asks whether the hosting fleet would take a plan; the build does
 * not ask again before it makes its job.
 *
 * Round three's first F-07 repair asked the fleet in both places. The
 * verifier measured what that did to a paid order whose capacity went away
 * between checkout and fulfilment — the last slot taken by a concurrent
 * order, or a load spike: the service was left PENDING with a
 * `placement_blocked_reason` and no provisioning job at all, so the engine
 * had nothing to retry, the operator's retry route had nothing to act on, and
 * renewal skipped it. Adding a node did not help. Before that repair the job
 * was made and CreateHostingAccountHandler failed it as
 * FailureClass::Capacity, which the engine retries — and that is the
 * behaviour these tests hold.
 */
final class APaidHostingOrderWaitsForCapacityRatherThanStallingTest extends TestCase
{
    use BuysSharedHosting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunProvisioningJob::class]);
    }

    #[Test]
    public function the_order_that_loses_the_race_for_the_last_slot_gets_a_job_that_waits(): void
    {
        $plan = $this->sharedHostingPlan('slot');
        HostingNode::query()->update(['max_accounts' => 1, 'account_count' => 0]);

        // Both are sold: at checkout the slot is free for each of them.
        $first = $this->placeSharedHostingOrder($this->customer(), $plan);
        $second = $this->placeSharedHostingOrder($this->customer(), $plan);

        $this->settleTheInvoiceOf($first);
        $this->runTheJobsOf($first);
        $this->assertSame(ServiceStatus::Active, $this->serviceOf($first)->status, 'Precondition: the first order took the slot.');

        $this->settleTheInvoiceOf($second);

        $service = $this->serviceOf($second);
        $this->assertArrayNotHasKey(
            'placement_blocked_reason',
            (array) $service->resources,
            'A paid order that only lacks capacity was parked as if nothing had been configured.',
        );
        $this->assertSame(1, ProvisioningJob::query()->where('order_id', $second->getKey())->count(), 'No job was made, so nothing will ever retry the build.');

        // The job meets the full node, and says why in the class the engine retries.
        $this->runTheJobsOf($second);
        $job = ProvisioningJob::query()->where('order_id', $second->getKey())->sole();
        $this->assertSame(FailureClass::Capacity, $job->failure_class);

        // An operator adds a node; the next attempt builds the account.
        HostingNode::factory()->create(['panel' => HostingPanel::Fake]);
        $job->forceFill(['next_attempt_at' => now()->subMinute()])->save();
        $this->runTheJobsOf($second);

        $this->assertSame(ServiceStatus::Active, $this->serviceOf($second)->status);
    }

    #[Test]
    public function a_load_spike_between_checkout_and_fulfilment_does_not_park_a_paid_order(): void
    {
        $order = $this->placeSharedHostingOrder($this->customer(), $this->sharedHostingPlan('load'));

        HostingNode::query()->update(['load_average' => 50.0]);

        $this->settleTheInvoiceOf($order);

        $this->assertArrayNotHasKey('placement_blocked_reason', (array) $this->serviceOf($order)->resources);
        $this->assertSame(1, ProvisioningJob::query()->where('order_id', $order->getKey())->count());

        // The load passes and the job builds the account.
        HostingNode::query()->update(['load_average' => 0.1]);
        $this->runTheJobsOf($order);

        $this->assertSame(ServiceStatus::Active, $this->serviceOf($order)->status);
    }

    #[Test]
    public function a_fleet_that_fills_between_checkout_and_payment_is_refused_before_any_money_moves(): void
    {
        /*
         * The other side of the split. Before money moves, the fleet IS asked:
         * checkout does, and so does the recheck at the moment a payment is
         * started (AssertOrderIsStillDeliverable), because the checkout's
         * answer may be minutes old. After money moves it is not asked again.
         */
        $order = $this->placeSharedHostingOrder($this->customer(), $this->sharedHostingPlan('full'));

        HostingNode::query()->update(['max_accounts' => 1, 'account_count' => 1]);

        $invoice = Invoice::query()->where('order_id', $order->getKey())->sole();

        try {
            app(StartInvoicePayment::class)->execute($invoice);
            $this->fail('A payment was started for a hosting plan no node can take any more.');
        } catch (CheckoutRejectedException $e) {
            $this->assertSame('checkout.not_deliverable', $e->errorCode());
        }

        $this->assertSame(0, Transaction::query()->count(), 'The refusal must land before the provider is asked.');
    }

    private function customer(): Customer
    {
        return Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
    }

    private function serviceOf(Order $order): Service
    {
        return Service::query()->where('order_id', $order->getKey())->sole();
    }

    private function runTheJobsOf(Order $order): void
    {
        foreach (ProvisioningJob::query()->where('order_id', $order->getKey())->get() as $job) {
            app()->call([new RunProvisioningJob((string) $job->getKey()), 'handle']);
        }
    }
}
