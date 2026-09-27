<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuysSharedHosting;
use Tests\TestCase;

/**
 * A change paid just before the renewal whose settlement is heard after it
 * still holds the next change (the "could not establish" item of the
 * re-audit after round six, established here).
 *
 * PlanChangeDelivery::aPaidChangeAwaitsDelivery() read only changes made in
 * the current period. A renewal between the capture and the settlement moved
 * the period on, and the paid, undelivered change stopped counting: the next
 * change was accepted (X1's window, reopened). A downgrade made then was
 * settled first, so when the paid upgrade's settlement arrived it found
 * itself superseded, recorded delivered, and queued nothing - the upgrade
 * paid for and never built, the money kept.
 */
final class AChangePaidBeforeTheRenewalAndSettledAfterItIsStillAwaitedTest extends TestCase
{
    use BuysSharedHosting;
    use RefreshDatabase;

    private Customer $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->user = User::factory()->create();
        $this->customer->members()->create(['user_id' => $this->user->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);
    }

    #[Test]
    public function a_change_paid_before_the_renewal_and_settled_after_it_holds_the_next_change(): void
    {
        $starter = $this->sharedHostingPlan('starter');
        $agency = $this->sharedHostingPlan('agency');
        PlanPrice::query()->where('plan_id', $agency->getKey())->update(['recurring_amount_minor' => 9_000]);

        $this->buySharedHosting($this->customer, $starter);
        $subscription = Subscription::query()->where('customer_id', $this->customer->getKey())->sole();

        // An hour before the period ends: upgrade, and pay; the settlement is not heard yet.
        $this->travelTo($subscription->current_period_end->subHour());
        $this->changePlan($subscription, $agency)->assertOk();
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->sole();
        Event::fakeFor(fn () => app(SettleInvoice::class)->execute($invoice, Transaction::factory()->forCustomer($this->customer)->amount(Money::ofMinor($invoice->total_minor, 'KWD'))->create()), [InvoicePaid::class]);

        // The renewal runs before the settlement is heard.
        $this->travelTo($subscription->current_period_end->addMinute());
        Artisan::call('subscriptions:renew');
        $this->assertTrue($subscription->fresh()?->current_period_start->greaterThan($subscription->current_period_start), 'The period did not renew.');

        // And the renewal is paid, so no open invoice holds the next change.
        /** @var Invoice $renewal */
        $renewal = Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->sole();
        app(SettleInvoice::class)->execute($renewal, Transaction::factory()->forCustomer($this->customer)->amount(Money::ofMinor($renewal->total_minor, 'KWD'))->create());

        $options = $this->actingAs($this->user)->getJson('/api/v1/subscriptions/'.$subscription->getKey().'/plan-options')->assertOk()->json('data');
        $starterOption = collect($options)->firstWhere('plan_id', (string) $starter->getKey());
        $this->assertContains('previous_change_pending', $starterOption['refusals'], 'A paid change settled after the renewal no longer holds the next change.');

        $this->changePlan($subscription, $starter)
            ->assertStatus(409)
            ->assertJsonPath('error.details.refusals', 'previous_change_pending');

        // The settlement arrives: the paid upgrade is built, not superseded.
        app(ResizeOnPlanChangeSettlement::class)->handle(new InvoicePaid((string) $invoice->getKey(), (string) $this->customer->getKey(), null, (string) $subscription->getKey(), now()->toImmutable()));

        $this->assertNotNull(PlanChange::query()->where('proration_invoice_id', $invoice->getKey())->sole()->delivered_at);
        $this->assertSame(1, ProvisioningJob::query()->where('kind', ProvisioningJobKind::ChangeHostingPackage->value)->where('idempotency_key', 'like', '%:invoice:'.$invoice->getKey())->count(), 'The paid upgrade was never built.');

        // And the next change is free again.
        $options = $this->actingAs($this->user)->getJson('/api/v1/subscriptions/'.$subscription->getKey().'/plan-options')->assertOk()->json('data');
        $this->assertNotContains('previous_change_pending', collect($options)->firstWhere('plan_id', (string) $starter->getKey())['refusals']);
    }

    private function changePlan(Subscription $subscription, Plan $plan): TestResponse
    {
        return $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'change-'.uniqid())
            ->postJson('/api/v1/subscriptions/'.$subscription->getKey().'/plan', [
                'plan_id' => (string) $plan->getKey(),
                'price_id' => (string) PlanPrice::query()->where('plan_id', $plan->getKey())->sole()->getKey(),
            ]);
    }
}
