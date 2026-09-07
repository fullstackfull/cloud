<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Subscriptions\Application\Actions\RenewDueSubscriptions;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The renewal sweep, which is what actually bills a customer for month two.
 *
 * Every part of this existed before the sweep did — the action that advances a
 * period, the engine that prices it, the action that issues the invoice — and
 * nothing joined them, so no subscription in this platform had ever renewed
 * outside a test that performed the handoff itself.
 */
final class RenewDueSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private RenewDueSubscriptions $sweep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sweep = app(RenewDueSubscriptions::class);
    }

    private function subscription(int $monthlyMinor = 9_000): Subscription
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        return Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'), BillingPeriod::Monthly)
            ->priced($monthlyMinor)
            ->create(['customer_id' => $customer->id]);
    }

    #[Test]
    public function a_due_subscription_is_renewed_and_invoiced(): void
    {
        $subscription = $this->subscription();

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));

        $result = $this->sweep->execute();

        $this->assertSame(1, $result->renewed);
        $this->assertSame(0, $result->failed);

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->id)->sole();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);

        // The invoice covers the new period, and the subscription's clock has
        // moved to match it.
        $subscription->refresh();
        $this->assertTrue($subscription->current_period_start->equalTo(CarbonImmutable::parse('2026-02-01 00:00:00')));
        $this->assertTrue($subscription->current_period_end->equalTo(CarbonImmutable::parse('2026-03-01 00:00:00')));
    }

    #[Test]
    public function a_second_run_in_the_same_minute_does_not_bill_twice(): void
    {
        $subscription = $this->subscription();

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));

        $this->sweep->execute();
        $second = $this->sweep->execute();

        // Nothing is due any more, so the second run has nothing to consider —
        // and above all it has issued no second invoice for a month that is
        // already billed.
        $this->assertSame(0, $second->renewed);
        $this->assertSame(1, Invoice::query()->where('subscription_id', $subscription->id)->count());
    }

    #[Test]
    public function a_subscription_that_is_not_due_is_left_alone(): void
    {
        $subscription = $this->subscription();

        $this->travelTo(CarbonImmutable::parse('2026-01-15 00:00:00'));

        $result = $this->sweep->execute();

        $this->assertSame(0, $result->considered);
        $this->assertSame(0, Invoice::query()->where('subscription_id', $subscription->id)->count());
    }

    /**
     * A subscription the platform cannot price.
     *
     * `subscriptions.currency` is three characters, not an ISO-4217 check, so a
     * bad import or a hand-edited row can carry a code no money library will
     * accept. It stands here for every per-row failure a sweep can meet: the
     * point is not this particular cause but that one unbillable row must not
     * abandon the thousand behind it.
     */
    private function unpriceableSubscription(): Subscription
    {
        $subscription = $this->subscription();

        Subscription::query()->whereKey($subscription->getKey())->update(['currency' => 'ZZZ']);

        return $subscription->refresh();
    }

    #[Test]
    public function one_broken_subscription_does_not_stop_the_rest_of_the_run(): void
    {
        $broken = $this->unpriceableSubscription();
        $healthy = $this->subscription();

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));

        $result = $this->sweep->execute();

        $this->assertSame(2, $result->considered);
        $this->assertSame(1, $result->renewed);
        $this->assertSame(1, $result->failed);
        $this->assertSame(1, Invoice::query()->where('subscription_id', $healthy->id)->count());
        $this->assertSame(0, Invoice::query()->where('subscription_id', $broken->id)->count());
    }

    #[Test]
    public function a_failed_renewal_leaves_the_period_where_it_was(): void
    {
        $broken = $this->unpriceableSubscription();
        $before = $broken->current_period_end;

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));

        $this->sweep->execute();

        /*
         * The period advance and the invoice for it commit together. A period
         * that moved without an invoice is a month the customer is never billed
         * for, and no later run can find it: the sweep would see nothing due
         * and the shortfall would never surface.
         */
        $this->assertTrue($broken->refresh()->current_period_end->equalTo($before));
        $this->assertSame(0, Invoice::query()->where('subscription_id', $broken->id)->count());
    }
}
