<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Application\Actions\RedeemCoupon;
use Lynomia\Modules\Catalog\Application\DTOs\CouponContext;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponCustomerLimitReachedException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponExpiredException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponFullyRedeemedException;
use Lynomia\Modules\Catalog\Domain\Exceptions\UnknownCouponException;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RedeemCouponTest extends TestCase
{
    use RefreshDatabase;

    private RedeemCoupon $redeem;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redeem = app(RedeemCoupon::class);
        $this->customer = Customer::factory()->create();
    }

    #[Test]
    public function redeeming_moves_the_counter_and_writes_the_audit_row_together(): void
    {
        $coupon = Coupon::factory()->percentage('0.100000')->limitedTo(3)->create();
        $order = Order::factory()->create(['customer_id' => $this->customer->id]);

        $redemption = $this->redeem->execute(
            $coupon,
            $this->context(Money::ofMinor(9_000, 'KWD')),
            (string) $order->id,
        );

        $this->assertSame($coupon->id, $redemption->coupon_id);
        $this->assertSame($this->customer->id, $redemption->customer_id);
        $this->assertSame($order->id, $redemption->order_id);
        $this->assertTrue($redemption->discount()->equals(Money::ofMinor(900, 'KWD')));
        $this->assertNotNull($redemption->created_at);

        $this->assertSame(1, (int) DB::table('coupons')->where('id', $coupon->id)->value('redemption_count'));
        $this->assertSame(1, DB::table('coupon_redemptions')->where('coupon_id', $coupon->id)->count());

        // The caller's own instance must not keep offering a coupon it has
        // just spent.
        $this->assertSame(1, $coupon->redemption_count);
    }

    #[Test]
    public function a_fixed_amount_redemption_stamps_the_exact_money_it_gave(): void
    {
        $coupon = Coupon::factory()->fixed(5_000, 'KWD')->create();

        $redemption = $this->redeem->execute($coupon, $this->context(Money::ofMinor(9_000, 'KWD')));

        $this->assertSame(5_000, $redemption->discount_amount_minor);
        $this->assertSame('KWD', $redemption->currency);
    }

    #[Test]
    public function a_refused_redemption_writes_nothing(): void
    {
        $coupon = Coupon::factory()->expired()->create();

        try {
            $this->redeem->execute($coupon, $this->context());
            $this->fail('Expected the expired coupon to be refused.');
        } catch (CouponExpiredException $e) {
            $this->assertSame('coupon.expired', $e->errorCode());
        }

        $this->assertSame(0, (int) DB::table('coupons')->where('id', $coupon->id)->value('redemption_count'));
        $this->assertSame(0, DB::table('coupon_redemptions')->where('coupon_id', $coupon->id)->count());
    }

    #[Test]
    public function the_global_limit_stops_the_campaign_for_everyone(): void
    {
        $coupon = Coupon::factory()->limitedTo(1)->perCustomer(5)->create();
        $second = Customer::factory()->create();

        $this->redeem->execute($coupon, $this->context());

        // The second customer has used nothing of their own allowance, but
        // the campaign itself is spent.
        $this->expectException(CouponFullyRedeemedException::class);
        $this->redeem->execute($coupon, $this->context(customer: $second));
    }

    #[Test]
    public function the_per_customer_limit_is_enforced_independently_of_the_global_limit(): void
    {
        // A hundred uses left globally, one per customer.
        $coupon = Coupon::factory()->limitedTo(100)->perCustomer(1)->create();
        $second = Customer::factory()->create();

        $this->redeem->execute($coupon, $this->context());

        try {
            $this->redeem->execute($coupon, $this->context());
            $this->fail('Expected the second redemption by the same customer to be refused.');
        } catch (CouponCustomerLimitReachedException $e) {
            $this->assertSame('coupon.customer_limit_reached', $e->errorCode());
            $this->assertSame($this->customer->id, $e->context()['customer_id']);
        }

        // A different customer is unaffected by the first customer's cap.
        $this->redeem->execute($coupon, $this->context(customer: $second));

        $this->assertSame(2, (int) DB::table('coupons')->where('id', $coupon->id)->value('redemption_count'));
        $this->assertSame(
            1,
            DB::table('coupon_redemptions')
                ->where('coupon_id', $coupon->id)
                ->where('customer_id', $this->customer->id)
                ->count(),
        );
    }

    #[Test]
    public function a_higher_per_customer_allowance_permits_repeat_use(): void
    {
        $coupon = Coupon::factory()->perCustomer(3)->create();

        $this->redeem->execute($coupon, $this->context());
        $this->redeem->execute($coupon, $this->context());
        $this->redeem->execute($coupon, $this->context());

        $this->assertSame(3, (int) DB::table('coupons')->where('id', $coupon->id)->value('redemption_count'));

        $this->expectException(CouponCustomerLimitReachedException::class);
        $this->redeem->execute($coupon, $this->context());
    }

    #[Test]
    public function a_pasted_code_can_be_resolved_and_redeemed_in_one_step(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'LAUNCH-25']);

        $redemption = $this->redeem->byCode(" launch-25\n", $this->context());

        $this->assertSame($coupon->id, $redemption->coupon_id);
        $this->assertSame(1, (int) DB::table('coupons')->where('id', $coupon->id)->value('redemption_count'));
    }

    #[Test]
    public function redeeming_an_unknown_code_writes_nothing(): void
    {
        $this->expectException(UnknownCouponException::class);

        try {
            $this->redeem->byCode('NOPE', $this->context());
        } finally {
            $this->assertSame(0, DB::table('coupon_redemptions')->count());
        }
    }

    private function context(?Money $orderAmount = null, ?Customer $customer = null): CouponContext
    {
        return new CouponContext(
            customer: $customer ?? $this->customer,
            orderAmount: $orderAmount ?? Money::ofMinor(9_000, 'KWD'),
        );
    }
}
