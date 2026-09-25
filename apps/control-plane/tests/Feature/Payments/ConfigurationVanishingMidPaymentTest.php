<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;
use Lynomia\Modules\Payments\Application\Actions\StartInvoicePayment;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentAttempt;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Application\Actions\ProvisionOrderedService;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LeavesNothingCommitted;
use Tests\TestCase;

/**
 * The window the feasibility gate does not close, and what is on the other
 * side of it.
 *
 * `StartInvoicePayment` re-asks whether the order can still be delivered
 * before it opens an intent. That is a read, not a lock, and it cannot be
 * anything else: the rows it consults belong to the catalogue and to the
 * estate, and holding them locked for the duration of a provider round trip
 * would stop every other checkout in the platform while one card form is open.
 *
 * So there is a window — between the answer and the intent — in which an
 * operator can delete the configuration the answer depended on. Narrow, and
 * real. A test that pretends the gate closes it would be a test of something
 * the code does not do, and the honest claim is the one asserted here:
 *
 *  1. the payment that was already past the gate is not corrupted by the
 *     removal — it does not half-happen, and the platform does not lose it;
 *  2. the service that payment bought is kept, marked, and findable rather
 *     than failed or silently absent;
 *  3. no second period is billed for it;
 *  4. the window is one attempt wide — the very next attempt on the same
 *     invoice is refused, because the gate now reads the removed row.
 *
 * The competing delete is committed from a second connection, at the moment
 * the payment has passed the gate and is writing its attempt. That is the one
 * point in the sequence where the race matters, and — as with the idempotency
 * race in ConcurrentOrderPlacementTest — hooking the model event is the only
 * way to be standing there when a single-threaded test arrives. The write
 * itself is genuine: another connection, its own transaction, committed.
 *
 * Fixtures are committed for real rather than living in the test transaction,
 * because a second connection cannot see a row that has never been committed,
 * and are removed again in a finally block.
 */
final class ConfigurationVanishingMidPaymentTest extends TestCase
{
    use LeavesNothingCommitted;
    use RefreshDatabase;

    /**
     * Nothing is wrapped in the test transaction.
     *
     * The truncate that puts the database back has to commit, and a truncate
     * issued inside RefreshDatabase's transaction is rolled back with it —
     * leaving exactly the committed rows it was there to remove. The same
     * arrangement FiniteCapacityIsClaimedNotGuessedTest uses, for the same
     * reason.
     *
     * @var list<string>
     */
    protected array $connectionsToTransact = [];

    private const string PAYER = 'payment_race_payer';

    private const string OPERATOR = 'payment_race_operator';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::PAYER, self::OPERATOR] as $name) {
            config()->set('database.connections.'.$name, config('database.connections.pgsql'));
        }
    }

    #[Test]
    public function a_package_deleted_after_the_gate_leaves_a_paid_service_visible_and_unbilled(): void
    {
        [$customer, $plan] = $this->committedFixtures();
        $previousDefault = DB::getDefaultConnection();

        $deleted = 0;

        try {
            // The action must run on a connection that really commits, or the
            // operator's connection would see none of the order it is racing.
            DB::setDefaultConnection(self::PAYER);

            $order = app(PlaceOrder::class)->execute($customer, new CheckoutRequest(
                lines: [new CheckoutLine($plan->id, 1, 'vanishing.example.test')],
                billingPeriod: BillingPeriod::Monthly,
                couponCode: null,
                idempotencyKey: null,
            ));

            /** @var Invoice $invoice */
            $invoice = Invoice::on(self::PAYER)->where('order_id', $order->getKey())->sole();

            /*
             * Past the gate, before the intent. An operator on another
             * connection retires the package this plan was sold against.
             */
            PaymentAttempt::creating(function () use (&$deleted, $plan): void {
                if ($deleted > 0) {
                    return;
                }

                $deleted = DB::connection(self::OPERATOR)
                    ->table('hosting_packages')
                    ->where('plan_id', $plan->getKey())
                    ->delete();
            });

            $started = app(StartInvoicePayment::class)->execute($invoice);

            /*
             * The positive control. Without it this test would pass against a
             * platform where the delete never happened, the hook never fired,
             * or the payment never got as far as reserving an attempt — and it
             * would be asserting nothing at all.
             */
            $this->assertSame(1, $deleted, 'The competing delete did not happen inside the window under test.');
            $this->assertSame(
                0,
                DB::connection(self::PAYER)->table('hosting_packages')->where('plan_id', $plan->getKey())->count(),
                'The package is still there; the race was not run.',
            );

            // 1. The payment that was already past the gate is whole: one
            //    attempt, one pending transaction, and they refer to each
            //    other. A half-written payment is money the platform cannot
            //    account for.
            $this->assertSame(1, Transaction::on(self::PAYER)->count());
            $this->assertSame((string) $started->transaction->getKey(), (string) Transaction::on(self::PAYER)->sole()->getKey());
            $this->assertSame(
                (string) $started->transaction->getKey(),
                (string) PaymentAttempt::on(self::PAYER)->sole()->transaction_id,
            );

            // 2. The service is kept and marked rather than failed or absent:
            //    the customer paid, and a row an operator can find is the only
            //    honest outcome.
            /** @var OrderItem $item */
            $item = OrderItem::on(self::PAYER)->where('order_id', $order->getKey())->sole();

            $service = app(ProvisionOrderedService::class)->execute($order->fresh(), $item);

            $this->assertNotNull($service);
            $this->assertSame(ServiceStatus::Pending, $service->status);

            $reason = ((array) $service->fresh()?->resources)['placement_blocked_reason'] ?? null;
            $this->assertIsString($reason);
            $this->assertStringContainsString('hosting package', $reason);

            // Nothing was asked of a panel that could not have answered.
            $this->assertSame(0, ProvisioningJob::on(self::PAYER)->count());

            // 4. The window is one attempt wide. The next press of Pay reads
            //    the row that is now gone and is refused — the gate is not
            //    disabled by having been passed once.
            try {
                app(StartInvoicePayment::class)->execute($invoice->fresh());
                $this->fail('A second payment was opened for an order the platform already knows it cannot deliver.');
            } catch (CheckoutRejectedException $e) {
                $this->assertSame('checkout.not_deliverable', $e->errorCode());
            }

            // And that refusal cost nothing: still the one transaction from
            // the payment that had already started.
            $this->assertSame(1, Transaction::on(self::PAYER)->count());
        } finally {
            PaymentAttempt::flushEventListeners();
            DB::setDefaultConnection($previousDefault);
        }
    }

    /**
     * A customer and a sellable hosting plan, committed so that both
     * connections can see them.
     *
     * @return array{0: Customer, 1: Plan}
     */
    private function committedFixtures(): array
    {
        $customer = Customer::factory()->make(['currency' => 'KWD', 'country' => 'KW']);
        $customer->setConnection(self::PAYER)->save();

        $product = Product::factory()->make(['kind' => ProductKind::SharedHosting->value]);
        $product->setConnection(self::PAYER)->save();

        $plan = Plan::factory()->make(['product_id' => $product->id]);
        $plan->setConnection(self::PAYER)->save();

        $price = PlanPrice::factory()->make([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9_000,
            'setup_amount_minor' => 0,
        ]);
        $price->setConnection(self::PAYER)->save();

        $package = HostingPackage::factory()->make(['plan_id' => $plan->id]);
        $package->setConnection(self::PAYER)->save();

        return [$customer, $plan->fresh(['prices', 'product'])];
    }
}
