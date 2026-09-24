<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;

/**
 * A mid-cycle plan change has to settle its money.
 *
 * ChangeSubscriptionPlan computes both halves of the proration — the unused
 * remainder of the plan being left, and the same remainder priced at the plan
 * being moved to — and returns them as two BillableLines carrying
 * InvoiceItemKind::Credit and ::Proration. The action then used those lines
 * for exactly two things: an audit entry recording `amount_due_now_minor`,
 * and the HTTP response body.
 *
 * Nothing made them durable. `ProrationPlan::pricingLines()` had no caller at
 * all, while its twin `RenewalPlan::pricingLines()` is called by
 * RenewDueSubscriptions — so the invoicing half was written and never wired.
 * The platform stated a debt, delivered the upgraded machine, and never
 * collected; and on a downgrade it computed the customer's credit and threw
 * it away. The loss ran in both directions.
 *
 * The product contract these tests pin:
 *
 *   UPGRADE   an invoice is issued carrying both halves, and the machine is
 *             NOT resized until that invoice settles.
 *   DOWNGRADE the customer's wallet is credited with the net, and the machine
 *             is resized immediately — they are receiving less, and owe
 *             nothing. A wallet credit is deliberately not a refund to the
 *             payment method: this domain already separates the two, and
 *             choosing the refund here would be inventing policy.
 *   NEITHER   a change with no price difference charges and credits nothing.
 */
final class APlanChangeSettlesItsMoneyTest extends BillingApiTestCase
{
    private Product $product;

    private Plan $small;

    private Plan $large;

    private Plan $twin;

    private Plan $wide;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunProvisioningJob::class]);
        $this->freezeTime();

        $this->product = Product::factory()->create(['kind' => 'vps']);
        $this->small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40], 9_000);
        $this->large = $this->plan('large', ['vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 80], 18_000);
        // Same money, same disk: a real change that costs nothing either way.
        $this->twin = $this->plan('twin', ['vcpu' => 3, 'memory_mib' => 6144, 'disk_gib' => 40], 9_000);
        /*
         * The plan a downgrade starts from. It is deliberately not `large`:
         * moving off 80 GiB onto small's 40 would truncate a filesystem, and
         * QuotePlanChange refuses that outright, so a downgrade test built on
         * `large` would be testing the refusal rather than the credit. Same
         * money as large, same disk as small — the change is real, it costs
         * less, and nothing is destroyed by it.
         */
        $this->wide = $this->plan('wide', ['vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 40], 18_000);
    }

    // ---- 1. the upgrade creates a durable, correct financial record --------

    #[Test]
    public function an_upgrade_issues_an_invoice_for_exactly_what_it_said_was_due(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $due = $this->changePlan($user, $subscription, $this->large)['data']['net'];

        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->sole();

        $this->assertSame(
            $due['minor_units'],
            $invoice->total_minor,
            'The invoice must bill exactly the amount the platform told the customer was due.'
        );
        $this->assertSame($due['currency'], $invoice->currency);
        $this->assertTrue($invoice->total_minor > 0, 'An upgrade owes money.');

        // Both halves are on the document, not just the net: an invoice that
        // showed only the difference would be unauditable.
        $kinds = $invoice->items()->pluck('kind')->map(
            static fn ($k): string => $k instanceof InvoiceItemKind ? $k->value : (string) $k
        )->all();

        $this->assertContains(InvoiceItemKind::Credit->value, $kinds);
        $this->assertContains(InvoiceItemKind::Proration->value, $kinds);

        $credit = $invoice->items()->where('kind', InvoiceItemKind::Credit->value)->sole();
        $charge = $invoice->items()->where('kind', InvoiceItemKind::Proration->value)->sole();

        $this->assertTrue($credit->total_minor < 0, 'The time left on the old plan is taken off.');
        $this->assertTrue($charge->total_minor > 0, 'The same time is charged at the new plan.');
    }

    // ---- 2. the entitlement is not delivered before the money settles ------

    #[Test]
    public function the_machine_is_not_resized_while_the_upgrade_invoice_is_unpaid(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $body = $this->changePlan($user, $subscription, $this->large)['data'];

        $this->assertSame(
            0,
            ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count(),
            'An upgrade must not hand over the bigger machine before it is paid for.'
        );
        Queue::assertNotPushed(RunProvisioningJob::class);

        /*
         * And the customer is told so. Withholding the machine while the
         * response reports a finished change would be the same defect wearing
         * different clothes: the portal would announce an upgrade that is not
         * going to happen until an invoice nobody mentioned is paid.
         */
        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->sole();

        $this->assertTrue($body['awaits_payment'], 'An unpaid upgrade owes money and must say so.');
        $this->assertSame((string) $invoice->getKey(), $body['invoice']['id'] ?? null);
        $this->assertSame($invoice->total_minor, $body['invoice']['total']['minor_units'] ?? null);
        $this->assertNull($body['resize'], 'Nothing is queued yet, so there is no job to report.');
        $this->assertTrue(
            $body['awaits_infrastructure'],
            'The machine still has to change; reporting otherwise would call the upgrade done.'
        );
    }

    // ---- 3. settlement delivers it, exactly once --------------------------

    #[Test]
    public function settling_the_invoice_resizes_the_machine_exactly_once(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large);
        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->sole();

        $this->settle($invoice, $customer);

        $jobs = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->get();
        $this->assertCount(1, $jobs, 'Settlement delivers the upgrade.');

        $payload = $jobs->first()?->payload ?? [];
        $this->assertSame($this->large->id, $payload['plan_id'] ?? null);
        $this->assertSame(4, $payload['vcpu'] ?? null);
        $this->assertSame(8192, $payload['memory_mib'] ?? null);
        $this->assertSame(80, $payload['disk_gib'] ?? null);

        // A redelivered settlement must not resize twice.
        $this->settle($invoice->fresh(), $customer);
        $this->assertSame(1, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count());
    }

    // ---- 4. the downgrade credit survives ---------------------------------

    #[Test]
    public function a_downgrade_credits_the_wallet_rather_than_discarding_the_money(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->wide);
        $this->serviceWithMachine($customer, $subscription);

        $ledger = app(WalletLedger::class);
        $before = $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits();

        $due = $this->changePlan($user, $subscription, $this->small)['data']['net'];
        $this->assertTrue($due['minor_units'] < 0, 'A downgrade owes the customer, not the platform.');

        $after = $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits();

        $this->assertSame(
            $before + abs($due['minor_units']),
            $after,
            "The customer's credit for time they will not use must not vanish."
        );

        // They are receiving less, so nothing is withheld pending payment.
        $this->assertSame(1, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count());
    }

    // ---- 5. a change that costs nothing charges nothing --------------------

    #[Test]
    public function a_change_with_no_price_difference_charges_and_credits_nothing(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $ledger = app(WalletLedger::class);
        $before = $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits();

        $due = $this->changePlan($user, $subscription, $this->twin)['data']['net'];

        $this->assertSame(0, $due['minor_units']);
        $this->assertSame(0, Invoice::query()->where('subscription_id', $subscription->getKey())->count());
        $this->assertSame($before, $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits());
        $this->assertSame(1, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->count());
    }

    // ---- 6. replay cannot bill twice --------------------------------------

    #[Test]
    public function replaying_the_same_plan_change_does_not_bill_twice(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large, 'replay-key');
        $this->changePlan($user, $subscription, $this->large, 'replay-key', assertOk: false);

        $this->assertSame(
            1,
            Invoice::query()->where('subscription_id', $subscription->getKey())->count(),
            'A retried plan change must never produce a second proration invoice.'
        );
    }

    // ---- 7. concurrency cannot bill twice ---------------------------------

    #[Test]
    public function two_plan_changes_at_once_cannot_both_bill(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        /*
         * The second attempt is fired from inside the first one's invoice
         * write, which is the exact instant the two would contend. The
         * subscription row is the mutex: ChangeSubscriptionPlan takes it for
         * update, so the loser sees a subscription already on the new plan and
         * is refused as a same-plan change rather than issuing a second
         * invoice.
         */
        $fired = false;

        Invoice::creating(function () use (&$fired, $user, $subscription): void {
            if ($fired) {
                return;
            }
            $fired = true;

            $this->changePlan($user, $subscription, $this->large, 'racer-bbb', assertOk: false);
        });

        try {
            $this->changePlan($user, $subscription, $this->large, 'racer-aaa', assertOk: false);
        } finally {
            Invoice::flushEventListeners();
        }

        $this->assertSame(
            1,
            Invoice::query()->where('subscription_id', $subscription->getKey())->count(),
            'Two concurrent plan changes must produce exactly one proration invoice.'
        );
        $this->assertSame($this->large->id, $subscription->fresh()?->plan_id);
    }

    // ---- 8. history is immutable ------------------------------------------

    #[Test]
    public function an_earlier_invoice_is_not_touched_by_a_later_plan_change(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $history = $this->invoiceFor($customer, ['subscription_id' => null, 'total_minor' => 9_000]);
        $before = $history->only(['total_minor', 'subtotal_minor', 'tax_minor', 'status', 'number']);

        $this->changePlan($user, $subscription, $this->large);

        $this->assertSame($before, $history->fresh()?->only(array_keys($before)));
    }

    // ---- 9. money stays integer minor units -------------------------------

    #[Test]
    public function every_amount_written_is_an_integer_in_the_subscriptions_currency(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large);

        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->sole();

        foreach (['subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor'] as $column) {
            $this->assertIsInt($invoice->{$column}, $column.' must be integer minor units, never a float.');
        }

        $this->assertSame($subscription->currency, $invoice->currency);

        foreach ($invoice->items as $item) {
            $this->assertIsInt($item->unit_amount_minor);
            $this->assertIsInt($item->total_minor);
        }
    }

    // ---- 10. renewal pricing is unchanged ---------------------------------

    #[Test]
    public function the_next_renewal_still_charges_the_new_recurring_price(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $this->changePlan($user, $subscription, $this->large);

        $this->assertSame(
            18_000,
            $subscription->fresh()?->recurring_amount_minor,
            'The plan change still moves what the next renewal will charge.'
        );
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function changePlan(
        $user,
        Subscription $subscription,
        Plan $plan,
        string $key = 'plan-change-1',
        bool $assertOk = true,
    ): array {
        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", [
                'plan_id' => $plan->id,
                'price_id' => $this->priceOf($plan)->id,
            ]);

        if ($assertOk) {
            $response->assertOk();
        }

        /*
         * Asserted even for the calls that are expected to be refused. An
         * Idempotency-Key shorter than eight characters is rejected by
         * validation before the controller is reached, and a test whose racing
         * request never ran at all would pass every "nothing was billed twice"
         * assertion while proving nothing. This caught exactly that.
         */
        $this->assertNotSame(
            'request.idempotency_key_rejected',
            $response->json('error.code'),
            'The request never reached the action, so whatever this test asserts next is vacuous.'
        );

        return (array) $response->json();
    }

    private function settle(Invoice $invoice, Customer $customer): void
    {
        $capture = Transaction::factory()
            ->forCustomer($customer)
            ->create(['amount_minor' => $invoice->total_minor, 'currency' => $invoice->currency]);

        app(SettleInvoice::class)->execute($invoice, $capture);
    }

    /**
     * @param  array<string, int>  $resources
     */
    private function plan(string $slug, array $resources, int $minor): Plan
    {
        $plan = Plan::factory()->create([
            'product_id' => $this->product->getKey(),
            'slug' => $slug,
            'resources' => $resources,
            'is_active' => true,
            'is_public' => true,
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $minor,
            'setup_amount_minor' => 0,
            'is_active' => true,
        ]);

        return $plan;
    }

    private function priceOf(Plan $plan): PlanPrice
    {
        return PlanPrice::query()->where('plan_id', $plan->getKey())->sole();
    }

    private function subscriptionOn(Customer $customer, Plan $plan): Subscription
    {
        return Subscription::factory()
            ->startingOn(CarbonImmutable::now()->subDays(10))
            ->create([
                'customer_id' => $customer->getKey(),
                'plan_id' => $plan->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => $this->priceOf($plan)->recurring_amount_minor,
            ]);
    }

    private function serviceWithMachine(Customer $customer, Subscription $subscription): Service
    {
        /*
         * Built to the shape of the plan the subscription is actually on.
         * QuotePlanChange compares the target against what the service records
         * rather than against the plan, so a fixture that always wrote the
         * small shape would make every change look like a resize onto or away
         * from small — and would have hidden a downgrade behind "nothing to
         * do".
         */
        $shape = $subscription->plan()->firstOrFail()->resources;

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'subscription_id' => $subscription->getKey(),
            'resources' => $shape,
        ]);

        $node = ComputeNode::factory()->create([
            'cluster_id' => ComputeCluster::factory()->create()->getKey(),
        ]);

        VirtualMachine::factory()
            ->onNode($node, 900)
            ->forService($service)
            ->create([
                'vcpu' => $shape['vcpu'],
                'memory_mib' => $shape['memory_mib'],
                'disk_gib' => $shape['disk_gib'],
            ]);

        return $service;
    }
}
