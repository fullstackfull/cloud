<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Application\Actions\RenderNotification;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Orders\Application\Services\PlanCapacity;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuysSharedHosting;
use Tests\TestCase;

/**
 * A plan change that stopped being deliverable between the opening of its
 * card payment and the capture is returned, not delivered as nothing and
 * kept (a residue the round-six verifiers recorded).
 *
 * The payment of a proration invoice asks whether the change can still be
 * delivered (PlanChangeDelivery::refusalForTheInvoice()) when the payment is
 * opened. A card payment is captured later, at the provider, and nothing asked
 * again: an operator who withdrew the package in between left the invoice
 * settled paid, the subscription on the plan it never got, the change
 * recorded delivered and nothing queued at the panel - with a log warning as
 * the only trace. The settlement now asks the same question, and a change it
 * refuses is returned: what the invoice holds goes back to the wallet against
 * the invoice, the subscription goes back to the plan and the amount it came
 * from, and the change's record, the audit trail and the log say so.
 *
 * Driven through the routes: the upgrade, the payment, the operator's
 * withdrawal and the controlled gateway's signed webhook.
 */
final class APlanChangeThatStoppedBeingDeliverableBeforeItsCaptureIsReturnedTest extends TestCase
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
    public function a_package_withdrawn_between_the_payment_and_its_capture_returns_the_money_and_the_plan(): void
    {
        $this->buySharedHosting($this->customer, $this->sharedHostingPlan('starter'));
        $subscription = Subscription::query()->where('customer_id', $this->customer->getKey())->sole();
        $fromPlanId = (string) $subscription->plan_id;
        $fromRecurring = (int) $subscription->recurring_amount_minor;

        [$planId, $priceId] = $this->operatorPutsAPlanOnSale($subscription);
        $package = $this->operatorMapsAPackage($planId);

        $this->changePlan($subscription, $planId, $priceId)->assertOk();
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->sole();
        $this->assertSame($planId, (string) $subscription->fresh()?->plan_id);

        // The card payment opens while the change can still be delivered.
        $reference = (string) $this->actingAs($this->user)
            ->postJson('/api/v1/invoices/'.$invoice->getKey().'/payments')
            ->assertCreated()
            ->json('data.reference');

        // The operator withdraws the package before the capture.
        $this->actingAs($this->operator)->deleteJson('/api/admin/catalogue/hosting-packages/'.$package)->assertOk();

        // The capture arrives, through the provider's signed webhook.
        $this->actingAs($this->user)
            ->postJson('/api/v1/fake-gateway/payments/'.$reference.'/approve')
            ->assertOk()
            ->assertJsonPath('data.webhook_status', 'processed');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status, 'The capture is the provider\'s fact and is recorded.');

        // Returned to the wallet, recorded against the invoice.
        $returned = WalletTransaction::query()->where('invoice_id', $invoice->getKey())->sum('amount_minor');
        $this->assertSame($invoice->total_minor, (int) $returned, 'What the capture took for an undeliverable change was kept.');
        $wallet = app(WalletLedger::class)->walletFor($this->customer, 'KWD');
        $this->assertSame($invoice->total_minor, app(WalletLedger::class)->balance($wallet)->minorUnits());

        // The subscription is back where it was, at the amount it paid.
        $fresh = $subscription->fresh();
        $this->assertSame($fromPlanId, (string) $fresh?->plan_id, 'The subscription stayed on a plan nothing delivered.');
        $this->assertSame($fromRecurring, $fresh?->recurring_amount_minor);

        // Nothing was queued, and the change is not recorded delivered.
        $this->assertSame(0, ProvisioningJob::query()->where('kind', ProvisioningJobKind::ChangeHostingPackage->value)->count());
        /** @var PlanChange $change */
        $change = PlanChange::query()->where('proration_invoice_id', $invoice->getKey())->sole();
        $this->assertNull($change->delivered_at, 'A change returned was recorded delivered.');
        $this->assertNotNull($change->returned_at, 'The change\'s record does not say it was returned.');
        $this->assertNotSame('', (string) $change->return_reason);

        // An operator can see it: the audit trail names the invoice and why.
        $entry = AuditEntry::query()
            ->where('action', AuditAction::PlanChanged->value)
            ->where('context->reason', 'plan_change_not_deliverable_at_settlement')
            ->sole();
        $this->assertSame((string) $invoice->getKey(), $entry->context['proration_invoice_id'] ?? null);
        $this->assertSame($invoice->total_minor, $entry->context['returned_to_wallet_minor'] ?? null);

        // The customer is told, in their language, where the money went.
        $notice = Notification::query()->where('customer_id', $this->customer->getKey())->where('type', 'billing.plan_change_returned')->sole();
        $amount = Money::ofMinor($invoice->total_minor, 'KWD')->format();
        $english = app(RenderNotification::class)->execute($notice, 'en')->body;
        $this->assertStringContainsString($amount.' has been returned to your wallet', $english);
        $this->assertStringContainsString($amount, app(RenderNotification::class)->execute($notice, 'ar')->body);

        // And the customer is free to change plans again: nothing awaits delivery.
        $options = $this->actingAs($this->user)
            ->getJson('/api/v1/subscriptions/'.$subscription->getKey().'/plan-options')
            ->assertOk()
            ->json('data');
        foreach ($options as $option) {
            $this->assertNotContains('previous_change_pending', $option['refusals'] ?? [], 'A returned change still reads as awaiting delivery.');
        }

        // The queue redelivers the settlement: nothing more is done or recorded.
        $this->redeliverTheSettlementOf($invoice);
        $this->assertSame(1, AuditEntry::query()->where('context->reason', 'plan_change_not_deliverable_at_settlement')->count(), 'A redelivered settlement returned the change a second time.');
        $this->assertSame($invoice->total_minor, app(WalletLedger::class)->balance($wallet->fresh() ?? $wallet)->minorUnits());
    }

    #[Test]
    public function a_change_still_deliverable_at_its_capture_is_delivered_as_before(): void
    {
        $this->buySharedHosting($this->customer, $this->sharedHostingPlan('starter'));
        $subscription = Subscription::query()->where('customer_id', $this->customer->getKey())->sole();

        [$planId, $priceId] = $this->operatorPutsAPlanOnSale($subscription);
        $package = $this->operatorMapsAPackage($planId);

        $this->changePlan($subscription, $planId, $priceId)->assertOk();
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->sole();

        $reference = (string) $this->actingAs($this->user)
            ->postJson('/api/v1/invoices/'.$invoice->getKey().'/payments')
            ->assertCreated()
            ->json('data.reference');
        $this->actingAs($this->user)->postJson('/api/v1/fake-gateway/payments/'.$reference.'/approve')->assertOk();

        $this->assertSame($planId, (string) $subscription->fresh()?->plan_id);
        $this->assertSame(0, (int) WalletTransaction::query()->where('invoice_id', $invoice->getKey())->sum('amount_minor'));
        /** @var PlanChange $change */
        $change = PlanChange::query()->where('proration_invoice_id', $invoice->getKey())->sole();
        $this->assertNotNull($change->delivered_at);
        $this->assertNull($change->returned_at);
        $this->assertSame(1, ProvisioningJob::query()->where('kind', ProvisioningJobKind::ChangeHostingPackage->value)->count());

        // Delivered; the package is withdrawn afterwards and the settlement
        // redelivered. What was delivered is not taken back.
        $this->actingAs($this->operator)->deleteJson('/api/admin/catalogue/hosting-packages/'.$package)->assertOk();
        $this->redeliverTheSettlementOf($invoice);

        $this->assertSame($planId, (string) $subscription->fresh()?->plan_id, 'A delivered change was returned by a redelivered settlement.');
        $this->assertSame(0, (int) WalletTransaction::query()->where('invoice_id', $invoice->getKey())->sum('amount_minor'));
        $this->assertNull($change->fresh()?->returned_at);
    }

    #[Test]
    public function a_change_a_later_settled_change_superseded_is_not_returned(): void
    {
        /*
         * A later change that was settled decided the plan (and a downgrade's
         * credit is drawn on this invoice): this one builds nothing, and is
         * not returned either - returning it would hand back money the later
         * change already accounted for. The rule the settlement applies to a
         * superseded change (PlanChangeDelivery::aLaterChangeWasSettled()).
         */
        $this->buySharedHosting($this->customer, $this->sharedHostingPlan('starter'));
        $subscription = Subscription::query()->where('customer_id', $this->customer->getKey())->sole();
        [$planId, $priceId] = $this->operatorPutsAPlanOnSale($subscription);
        $package = $this->operatorMapsAPackage($planId);

        $this->changePlan($subscription, $planId, $priceId)->assertOk();
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->sole();
        /** @var PlanChange $change */
        $change = PlanChange::query()->where('proration_invoice_id', $invoice->getKey())->sole();

        // Paid with the settlement held back; a later change that owed
        // nothing is recorded; the package is withdrawn.
        Event::fakeFor(fn () => app(SettleInvoice::class)->execute($invoice, Transaction::factory()->forCustomer($this->customer)->amount(Money::ofMinor($invoice->total_minor, 'KWD'))->create()), [InvoicePaid::class]);
        (new PlanChange)->forceFill([
            'subscription_id' => (string) $subscription->getKey(),
            'from_plan_id' => $planId,
            'to_plan_id' => $planId,
            'currency' => 'KWD',
            'units' => 1,
            'credit_minor' => 0,
            'charge_minor' => 0,
            'wallet_credit_minor' => 0,
            'proration_invoice_id' => null,
            'resources' => [],
            'changed_at' => $change->changed_at->addSecond(),
        ])->save();
        $this->actingAs($this->operator)->deleteJson('/api/admin/catalogue/hosting-packages/'.$package)->assertOk();

        $this->redeliverTheSettlementOf($invoice->fresh() ?? $invoice);

        $this->assertNull($change->fresh()?->returned_at, 'A superseded change was returned.');
        $this->assertNotNull($change->fresh()?->delivered_at);
        $this->assertSame(0, (int) WalletTransaction::query()->where('invoice_id', $invoice->getKey())->sum('amount_minor'));
    }

    #[Test]
    public function a_return_to_a_plan_whose_last_unit_sold_in_the_window_is_made_and_the_excess_recorded(): void
    {
        /*
         * F-06's residue (the re-audit after round six): a paid upgrade stops
         * counting its unit against the plan it left, so that plan can sell
         * the unit between the payment and the settlement. The return puts
         * the subscription back on it all the same - it is the plan the
         * customer already held, and the service never left its shape - so
         * the plan's stock_limit is exceeded, by at most the change's units.
         * Not refused, and not silent: the audit entry and the log say by
         * how much.
         */
        $starter = $this->sharedHostingPlan('starter', stockLimit: 1);
        $this->buySharedHosting($this->customer, $starter);
        $subscription = Subscription::query()->where('customer_id', $this->customer->getKey())->sole();
        [$planId, $priceId] = $this->operatorPutsAPlanOnSale($subscription);
        $package = $this->operatorMapsAPackage($planId);

        $this->changePlan($subscription, $planId, $priceId)->assertOk();
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->sole();

        // Paid, the settlement not yet heard: the unit on the starter plan is given up.
        Event::fakeFor(fn () => app(SettleInvoice::class)->execute($invoice, Transaction::factory()->forCustomer($this->customer)->amount(Money::ofMinor($invoice->total_minor, 'KWD'))->create()), [InvoicePaid::class]);

        // And sold to another account.
        $another = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->buySharedHosting($another, $starter);
        $this->assertSame(1, app(PlanCapacity::class)->claimed((string) $starter->getKey()));

        $this->actingAs($this->operator)->deleteJson('/api/admin/catalogue/hosting-packages/'.$package)->assertOk();
        $this->redeliverTheSettlementOf($invoice->fresh() ?? $invoice);

        $this->assertNotNull(PlanChange::query()->where('proration_invoice_id', $invoice->getKey())->sole()->returned_at);
        $this->assertSame((string) $starter->getKey(), (string) $subscription->fresh()?->plan_id, 'A return to the plan the customer held was refused for its stock.');
        $this->assertSame(2, app(PlanCapacity::class)->claimed((string) $starter->getKey()));

        $entry = AuditEntry::query()->where('context->reason', 'plan_change_not_deliverable_at_settlement')->sole();
        $this->assertSame(1, $entry->context['plan_stock_exceeded_by'] ?? null, 'The plan was put over its stock limit and nothing said so.');
    }

    #[Test]
    public function the_plan_is_not_put_back_over_a_change_recorded_after_the_one_returned(): void
    {
        /*
         * The subscription goes back to the plan the returned change left
         * only when nothing was changed after it - the rule a voided
         * upgrade's restore follows (RestorePlanOnVoidedUpgrade). A later
         * change still waiting on its own invoice supersedes nothing, so the
         * money is returned all the same; the plan is left to that change.
         */
        $this->buySharedHosting($this->customer, $this->sharedHostingPlan('starter'));
        $subscription = Subscription::query()->where('customer_id', $this->customer->getKey())->sole();
        $fromPlanId = (string) $subscription->plan_id;
        [$planId, $priceId] = $this->operatorPutsAPlanOnSale($subscription);
        $package = $this->operatorMapsAPackage($planId);

        $this->changePlan($subscription, $planId, $priceId)->assertOk();
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->sole();
        /** @var PlanChange $change */
        $change = PlanChange::query()->where('proration_invoice_id', $invoice->getKey())->sole();

        Event::fakeFor(fn () => app(SettleInvoice::class)->execute($invoice, Transaction::factory()->forCustomer($this->customer)->amount(Money::ofMinor($invoice->total_minor, 'KWD'))->create()), [InvoicePaid::class]);

        // A later change recorded against an invoice still open: not settled.
        $open = Invoice::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => 100,
            'total_minor' => 100,
        ]);
        (new PlanChange)->forceFill([
            'subscription_id' => (string) $subscription->getKey(),
            'from_plan_id' => $planId,
            'to_plan_id' => $planId,
            'currency' => 'KWD',
            'units' => 1,
            'credit_minor' => 0,
            'charge_minor' => 100,
            'wallet_credit_minor' => 0,
            'proration_invoice_id' => (string) $open->getKey(),
            'resources' => [],
            'changed_at' => $change->changed_at->addSecond(),
        ])->save();
        $this->actingAs($this->operator)->deleteJson('/api/admin/catalogue/hosting-packages/'.$package)->assertOk();

        $this->redeliverTheSettlementOf($invoice->fresh() ?? $invoice);

        $this->assertNotNull($change->fresh()?->returned_at);
        $this->assertSame($invoice->total_minor, (int) WalletTransaction::query()->where('invoice_id', $invoice->getKey())->sum('amount_minor'));
        $this->assertSame($planId, (string) $subscription->fresh()?->plan_id, 'The plan was put back over a change recorded after the one returned.');
        $this->assertNotSame($fromPlanId, (string) $subscription->fresh()?->plan_id);

        /*
         * And the customer is not told the service stays on its current plan
         * (N4, the re-audit after round six): the plan was left to the later
         * change. What is true either way: the service was not changed, and
         * the money went back.
         */
        $notice = Notification::query()->where('customer_id', $this->customer->getKey())->where('type', 'billing.plan_change_returned')->sole();
        $english = app(RenderNotification::class)->execute($notice, 'en')->body;
        $arabic = app(RenderNotification::class)->execute($notice, 'ar')->body;
        $this->assertStringNotContainsString('stays on its current plan', $english);
        $this->assertStringNotContainsString('على خطته الحالية', $arabic);
        $this->assertStringContainsString('has not been changed', $english);
        $this->assertStringContainsString('فلم يُغيَّر', $arabic);
    }

    private function redeliverTheSettlementOf(Invoice $invoice): void
    {
        app(ResizeOnPlanChangeSettlement::class)->handle(new InvoicePaid(
            (string) $invoice->getKey(),
            (string) $invoice->customer_id,
            null,
            (string) $invoice->subscription_id,
            CarbonImmutable::now(),
        ));
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
            ->withHeader('Idempotency-Key', 'upgrade-'.uniqid())
            ->postJson('/api/v1/subscriptions/'.$subscription->getKey().'/plan', ['plan_id' => $planId, 'price_id' => $priceId]);
    }
}
