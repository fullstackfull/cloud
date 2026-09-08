<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Subscriptions\Application\Actions\RenewSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two renewal workers, one subscription, two real connections.
 *
 * The cost of getting this wrong is a customer billed twice for one month, and
 * the platform now runs this sweep on a schedule across every application
 * server, so two workers meeting on the same row is the expected case rather
 * than a rare one.
 *
 * As in the IPAM and wallet concurrency tests, the rows under examination are
 * committed for real: RefreshDatabase's transaction is invisible to a second
 * connection, and two statements on one connection are serialised by definition
 * and can never race.
 */
final class ConcurrentRenewalTest extends TestCase
{
    use RefreshDatabase;

    private const string WORKER_A = 'renew_a';

    private const string WORKER_B = 'renew_b';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::WORKER_A, self::WORKER_B] as $name) {
            config()->set('database.connections.'.$name, config('database.connections.pgsql'));
        }

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));
    }

    #[Test]
    public function a_second_worker_cannot_advance_a_period_that_is_being_advanced(): void
    {
        $customer = Customer::factory()->make(['currency' => 'KWD', 'country' => 'KW']);
        $customer->setConnection(self::WORKER_A)->save();

        // The whole fixture is committed on worker A, plan and product
        // included: a relationship factory would write them inside the test
        // transaction, where the other connection cannot see them.
        $product = Product::factory()->make();
        $product->setConnection(self::WORKER_A)->save();

        $plan = Plan::factory()->make(['product_id' => $product->id]);
        $plan->setConnection(self::WORKER_A)->save();

        try {
            $subscription = Subscription::factory()
                ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'), BillingPeriod::Monthly)
                ->priced(9_000)
                ->make(['customer_id' => $customer->id, 'plan_id' => $plan->id]);
            $subscription->setConnection(self::WORKER_A)->save();

            $periodEndBefore = $subscription->current_period_end;
            $previousDefault = null;

            // The contender read the subscription before anything moved, which
            // is exactly the state a second worker is in when both sweeps pick
            // the same row out of the due list.
            $contender = Subscription::on(self::WORKER_B)->findOrFail($subscription->id);
            $this->assertTrue($contender->current_period_end->equalTo($periodEndBefore));

            /*
             * Without a lock timeout the contender would wait for a lock only
             * the caller further up this same PHP stack can release, and the
             * test would hang rather than fail.
             */
            DB::connection(self::WORKER_B)->statement("SET lock_timeout = '1s'");

            $outcome = null;
            $fired = false;

            /*
             * RenewSubscription reads and locks through the default connection,
             * as every action in the platform does. Which worker it *is*, then,
             * is decided by which connection is default while it runs — so the
             * default is moved for the duration of each call rather than by
             * handing the action a model that carries its own connection, which
             * it would not use for the locking read.
             */
            $previousDefault = DB::getDefaultConnection();
            DB::setDefaultConnection(self::WORKER_A);

            // Fires inside the first renewal's transaction, after it has taken
            // the subscription row and before it has written the new period.
            Subscription::updating(function () use (&$outcome, &$fired, $contender): void {
                if ($fired) {
                    return;
                }

                $fired = true;

                DB::setDefaultConnection(self::WORKER_B);

                try {
                    app(RenewSubscription::class)->execute($contender);
                    $outcome = 'advanced';
                } catch (QueryException) {
                    $outcome = 'blocked';
                } finally {
                    DB::setDefaultConnection(self::WORKER_A);
                }
            });

            $renewal = app(RenewSubscription::class)->execute(
                Subscription::on(self::WORKER_A)->findOrFail($subscription->id),
            );

            $this->assertNotNull($renewal, 'The first worker must have renewed.');
            $this->assertSame(
                'blocked',
                $outcome,
                'The second worker must wait on the subscription row rather than advancing a period that is already moving.',
            );

            // And once the winner has committed, the loser's stale copy renews
            // nothing: the locked row no longer matches what it read.
            DB::setDefaultConnection(self::WORKER_B);

            $this->assertNull(
                app(RenewSubscription::class)->execute($contender),
                'A worker holding a stale copy must not advance a period that has already moved.',
            );

            $after = Subscription::on(self::WORKER_A)->findOrFail($subscription->id);

            // One month, not two. This is the assertion the whole test exists
            // for: the customer is billed for February, once.
            $this->assertTrue($after->current_period_start->equalTo($periodEndBefore));
            $this->assertTrue($after->current_period_end->equalTo(CarbonImmutable::parse('2026-03-01 00:00:00')));
        } finally {
            DB::setDefaultConnection($previousDefault ?? 'pgsql');
            Subscription::flushEventListeners();
            DB::connection(self::WORKER_B)->statement('SET lock_timeout = 0');
            Customer::on(self::WORKER_A)->whereKey($customer->id)->forceDelete();
            Plan::on(self::WORKER_A)->whereKey($plan->id)->delete();
            Product::on(self::WORKER_A)->whereKey($product->id)->delete();
        }
    }
}
