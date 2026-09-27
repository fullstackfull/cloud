<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Actions\ReturnAnUpgradeTheEndPrevented;
use Lynomia\Modules\Subscriptions\Application\Queries\PlanChangeDelivery;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A plan change returned at its settlement supersedes nothing (a residue the
 * round-six verifier's sweep of `returned_at` named).
 *
 * "A later change was settled" read a later change's paid invoice as having
 * decided the machine. A change paid and then returned
 * (ReturnAPlanChangeNoLongerDeliverable) keeps its invoice paid but decided
 * nothing: an earlier paid change whose settlement was never heard is still
 * undelivered. Counted as superseded, it was kept when the subscription
 * ended - money for nothing.
 */
final class AReturnedPlanChangeSupersedesNothingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_earlier_undelivered_upgrade_is_returned_at_the_end_despite_a_later_returned_change(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        [$small, $mid, $large] = [Plan::factory()->create(), Plan::factory()->create(), Plan::factory()->create()];
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->getKey(),
            'plan_id' => $mid->getKey(),
            'currency' => 'KWD',
            'status' => SubscriptionStatus::Cancelled,
        ]);

        $first = $this->paidUpgrade($customer, $subscription, $small, $mid, 3_000, CarbonImmutable::now()->subDays(40));
        $later = $this->paidUpgrade($customer, $subscription, $mid, $large, 5_000, CarbonImmutable::now()->subDays(5));
        PlanChange::query()->whereKey($later->getKey())->update(['returned_at' => now(), 'return_reason' => 'the package was withdrawn']);

        $this->assertFalse(app(PlanChangeDelivery::class)->aLaterChangeWasSettled($first), 'A returned change was read as having decided the machine.');

        $credited = app(ReturnAnUpgradeTheEndPrevented::class)->execute((string) $first->proration_invoice_id);

        $this->assertSame(3_000, $credited, 'An undelivered upgrade was kept at the end because a returned change looked like it superseded it.');
        $this->assertSame(3_000, (int) WalletTransaction::query()->where('invoice_id', $first->proration_invoice_id)->sum('amount_minor'));
    }

    #[Test]
    public function a_later_settled_change_that_was_not_returned_still_supersedes(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        [$small, $mid, $large] = [Plan::factory()->create(), Plan::factory()->create(), Plan::factory()->create()];
        $subscription = Subscription::factory()->create(['customer_id' => $customer->getKey(), 'plan_id' => $large->getKey(), 'currency' => 'KWD']);

        $first = $this->paidUpgrade($customer, $subscription, $small, $mid, 3_000, CarbonImmutable::now()->subDays(40));
        $this->paidUpgrade($customer, $subscription, $mid, $large, 5_000, CarbonImmutable::now()->subDays(5));

        $this->assertTrue(app(PlanChangeDelivery::class)->aLaterChangeWasSettled($first));
    }

    private function paidUpgrade(Customer $customer, Subscription $subscription, Plan $from, Plan $to, int $minor, CarbonImmutable $at): PlanChange
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'currency' => 'KWD',
            'status' => InvoiceStatus::Paid,
            'subtotal_minor' => $minor,
            'total_minor' => $minor,
            'amount_paid_minor' => $minor,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->getKey(),
            'kind' => InvoiceItemKind::Proration,
            'description' => 'Upgrade',
            'quantity' => 1,
            'unit_amount_minor' => $minor,
            'total_minor' => $minor,
            'subscription_id' => $subscription->getKey(),
        ]);
        Transaction::factory()->forCustomer($customer)->amount(Money::ofMinor($minor, 'KWD'))->create(['invoice_id' => $invoice->getKey()]);

        $change = new PlanChange;
        $change->forceFill([
            'id' => (string) Str::ulid(),
            'subscription_id' => (string) $subscription->getKey(),
            'from_plan_id' => (string) $from->getKey(),
            'to_plan_id' => (string) $to->getKey(),
            'currency' => 'KWD',
            'units' => 1,
            'credit_minor' => 0,
            'charge_minor' => $minor,
            'wallet_credit_minor' => 0,
            'from_recurring_amount_minor' => 1_000,
            'proration_invoice_id' => (string) $invoice->getKey(),
            'resources' => [],
            'changed_at' => $at,
        ])->save();

        return $change;
    }
}
