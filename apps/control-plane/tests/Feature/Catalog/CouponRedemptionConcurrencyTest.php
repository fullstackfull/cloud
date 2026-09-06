<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Application\Actions\RedeemCoupon;
use Lynomia\Modules\Catalog\Application\DTOs\CouponContext;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponCustomerLimitReachedException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponFullyRedeemedException;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two simultaneous checkouts against the same coupon, on two real connections.
 *
 * Nothing here is mocked. A coupon with one use left is a scarce resource, and
 * the only thing standing between it and being given away twice is a
 * PostgreSQL row lock — which cannot be exercised at all from a single
 * connection, and cannot be observed from inside the transaction the test
 * suite normally wraps each test in.
 */
final class CouponRedemptionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const string SECOND_CHECKOUT = 'pgsql_checkout_b';

    /**
     * This test class commits its data instead of running inside a wrapping
     * transaction.
     *
     * It has to: a coupon written inside an uncommitted transaction is
     * invisible to the second connection, and a FOR UPDATE issued on that
     * connection would queue behind a lock the test process itself is holding
     * and never wake up. Committing is what makes the two checkouts genuinely
     * independent — and is why tearDown truncates rather than rolling back.
     *
     * @var list<string>
     */
    protected array $connectionsToTransact = [];

    private RedeemCoupon $redeem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redeem = app(RedeemCoupon::class);

        // A second connection to the same database, standing in for a second
        // checkout in another PHP process.
        config([
            'database.connections.'.self::SECOND_CHECKOUT => config(
                'database.connections.'.config('database.default')
            ),
        ]);
    }

    protected function tearDown(): void
    {
        $this->resetLockTimeout(DB::connection());
        DB::purge(self::SECOND_CHECKOUT);

        // Cascades into coupon_redemptions and orders. Nothing rolled back for
        // us, so the next test starts from an empty table set.
        DB::statement('TRUNCATE coupons, customers, users RESTART IDENTITY CASCADE');

        parent::tearDown();
    }

    #[Test]
    public function two_simultaneous_checkouts_redeem_a_single_use_coupon_exactly_once(): void
    {
        $coupon = Coupon::factory()->limitedTo(1)->perCustomer(1)->create();
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();

        /*
         * Both checkouts read the coupon before either writes. This is the
         * race: each holds a copy saying "nobody has used this yet", and a
         * validator that trusted those copies would let both through.
         */
        $couponForA = Coupon::query()->findOrFail($coupon->id);
        $couponForB = Coupon::on(self::SECOND_CHECKOUT)->findOrFail($coupon->id);

        $this->assertNotSame(
            $couponForA->getConnection()->getPdo(),
            $couponForB->getConnection()->getPdo(),
            'The two checkouts must be on genuinely separate connections.',
        );
        $this->assertSame(0, $couponForA->redemption_count);
        $this->assertSame(0, $couponForB->redemption_count);

        $this->redeem->execute($couponForA, $this->context($customerA));

        try {
            $this->redeem->execute($couponForB, $this->context($customerB));
            $this->fail('The second checkout redeemed a coupon that had no uses left.');
        } catch (CouponFullyRedeemedException $e) {
            $this->assertSame('coupon.fully_redeemed', $e->errorCode());
            // Reported from the locked row, so it is the committed count and
            // not the zero this checkout had cached.
            $this->assertSame(1, $e->context()['redemption_count']);
        }

        $this->assertRedeemedExactlyOnce($coupon->id, $customerA->id);
    }

    #[Test]
    public function a_checkout_waits_on_the_row_lock_the_other_checkout_holds(): void
    {
        $coupon = Coupon::factory()->limitedTo(1)->perCustomer(1)->create();
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();

        $second = DB::connection(self::SECOND_CHECKOUT);

        // Checkout B gets to the coupon row first and holds it.
        $second->beginTransaction();
        $second->select('SELECT id FROM coupons WHERE id = ? FOR UPDATE', [$coupon->id]);

        /*
         * Checkout A now has to wait for it. In a single process that wait
         * would never end, so the connection is told to give up after half a
         * second: the timeout error is the proof that A really did queue
         * behind B's lock instead of reading around it.
         */
        DB::statement("SET lock_timeout = '500ms'");

        try {
            $this->redeem->execute($coupon, $this->context($customerA));
            $this->fail('Checkout A read the coupon while checkout B held its lock.');
        } catch (QueryException $e) {
            // 55P03 is lock_not_available.
            $this->assertSame('55P03', $e->getCode());

            /*
             * And it gave up on the SELECT ... FOR UPDATE, not on the later
             * UPDATE. That distinction is the whole point: a redemption that
             * only locked when it came to write would already have read — and
             * validated against — a redemption_count that another checkout was
             * in the middle of changing.
             */
            $this->assertStringContainsString('for update', strtolower($e->getSql()));
        }

        $this->resetLockTimeout(DB::connection());

        // A was blocked before it could write anything, and its transaction
        // rolled back cleanly.
        $this->assertSame(0, DB::table('coupon_redemptions')->count());
        $this->assertSame(0, (int) DB::table('coupons')->where('id', $coupon->id)->value('redemption_count'));

        // B releases the bare lock and completes its checkout for real.
        $second->rollBack();
        $this->redeem->execute(
            Coupon::on(self::SECOND_CHECKOUT)->findOrFail($coupon->id),
            $this->context($customerB),
        );

        // A retries. The lock is free now, but the coupon is spent.
        try {
            $this->redeem->execute($coupon->fresh() ?? $coupon, $this->context($customerA));
            $this->fail('Checkout A redeemed a coupon that checkout B had already taken.');
        } catch (CouponFullyRedeemedException $e) {
            $this->assertSame('coupon.fully_redeemed', $e->errorCode());
        }

        $this->assertRedeemedExactlyOnce($coupon->id, $customerB->id);
    }

    #[Test]
    public function two_simultaneous_checkouts_by_one_customer_respect_the_per_customer_limit(): void
    {
        // No global cap at all: only the per-customer limit stands between
        // this customer and two discounts, and it is counted from rows that
        // the other checkout has not committed yet.
        $coupon = Coupon::factory()->perCustomer(1)->create();
        $customer = Customer::factory()->create();

        $couponForA = Coupon::query()->findOrFail($coupon->id);
        $couponForB = Coupon::on(self::SECOND_CHECKOUT)->findOrFail($coupon->id);

        $this->redeem->execute($couponForA, $this->context($customer));

        try {
            $this->redeem->execute($couponForB, $this->context($customer));
            $this->fail('The same customer redeemed a one-per-customer coupon twice.');
        } catch (CouponCustomerLimitReachedException $e) {
            $this->assertSame('coupon.customer_limit_reached', $e->errorCode());
        }

        $this->assertRedeemedExactlyOnce($coupon->id, $customer->id);
    }

    private function assertRedeemedExactlyOnce(string $couponId, string $customerId): void
    {
        $rows = DB::table('coupon_redemptions')->where('coupon_id', $couponId)->get();

        $this->assertCount(1, $rows, 'The coupon was redeemed more than once.');
        $this->assertSame($customerId, $rows->first()?->customer_id);
        $this->assertSame(1, (int) DB::table('coupons')->where('id', $couponId)->value('redemption_count'));
    }

    private function context(Customer $customer): CouponContext
    {
        return new CouponContext(
            customer: $customer,
            orderAmount: Money::ofMinor(9_000, 'KWD'),
        );
    }

    private function resetLockTimeout(Connection $connection): void
    {
        try {
            $connection->statement('SET lock_timeout = 0');
        } catch (QueryException) {
            // The connection may already be gone during teardown; the session
            // setting dies with it either way.
        }
    }
}
