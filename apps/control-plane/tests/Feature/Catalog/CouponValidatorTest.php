<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Catalog\Application\DTOs\CouponContext;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponCurrencyMismatchException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponCustomerLimitReachedException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponExpiredException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponFullyRedeemedException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponInactiveException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponNotApplicableException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponNotYetValidException;
use Lynomia\Modules\Catalog\Domain\Exceptions\OrderBelowCouponMinimumException;
use Lynomia\Modules\Catalog\Domain\Exceptions\UnknownCouponException;
use Lynomia\Modules\Catalog\Domain\Services\CouponValidator;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Catalog\Infrastructure\Models\CouponRedemption;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CouponValidatorTest extends TestCase
{
    use RefreshDatabase;

    private CouponValidator $validator;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = app(CouponValidator::class);
        $this->customer = Customer::factory()->create();
    }

    #[Test]
    public function an_unknown_code_is_refused_with_its_own_error_code(): void
    {
        $this->assertRefusal(
            Coupon::factory()->create(['code' => 'LAUNCH25']),
            UnknownCouponException::class,
            'coupon.unknown_code',
            fn (): Coupon => $this->validator->validateCode('NOT-A-CODE', $this->context()),
        );
    }

    #[Test]
    public function an_inactive_code_is_refused_with_its_own_error_code(): void
    {
        $coupon = Coupon::factory()->inactive()->create();

        $this->assertRefusal($coupon, CouponInactiveException::class, 'coupon.inactive');
    }

    #[Test]
    public function a_code_used_before_its_start_date_is_refused_with_its_own_error_code(): void
    {
        $coupon = Coupon::factory()->notYetValid()->create();

        $this->assertRefusal($coupon, CouponNotYetValidException::class, 'coupon.not_yet_valid');
    }

    #[Test]
    public function a_code_used_after_its_end_date_is_refused_with_its_own_error_code(): void
    {
        $coupon = Coupon::factory()->expired()->create();

        $this->assertRefusal($coupon, CouponExpiredException::class, 'coupon.expired');
    }

    #[Test]
    public function a_code_that_has_reached_its_global_limit_is_refused_with_its_own_error_code(): void
    {
        $coupon = Coupon::factory()->limitedTo(5)->create(['redemption_count' => 5]);

        $exception = $this->assertRefusal($coupon, CouponFullyRedeemedException::class, 'coupon.fully_redeemed');
        $this->assertSame(5, $exception->context()['redemption_count']);
        $this->assertSame(5, $exception->context()['max_redemptions']);
    }

    #[Test]
    public function a_customer_who_has_reached_their_own_limit_is_refused_with_its_own_error_code(): void
    {
        $coupon = Coupon::factory()->perCustomer(2)->create();

        CouponRedemption::factory()->count(2)->create([
            'coupon_id' => $coupon->id,
            'customer_id' => $this->customer->id,
        ]);

        $exception = $this->assertRefusal(
            $coupon,
            CouponCustomerLimitReachedException::class,
            'coupon.customer_limit_reached',
        );
        $this->assertSame($this->customer->id, $exception->context()['customer_id']);
    }

    #[Test]
    public function an_order_below_the_minimum_is_refused_with_its_own_error_code(): void
    {
        $coupon = Coupon::factory()->minimumOrder(10_000)->create();

        $exception = $this->assertRefusal(
            $coupon,
            OrderBelowCouponMinimumException::class,
            'coupon.order_below_minimum',
            fn (): Coupon => $this->validator->validateCode(
                $coupon->code,
                $this->context(Money::ofMinor(9_999, 'KWD')),
            ),
        );
        $this->assertSame(10_000, $exception->context()['minimum_order_amount_minor']);
    }

    #[Test]
    public function a_fixed_amount_coupon_in_another_currency_is_refused_with_its_own_error_code(): void
    {
        $coupon = Coupon::factory()->fixed(5_000, 'KWD')->create();

        $exception = $this->assertRefusal(
            $coupon,
            CouponCurrencyMismatchException::class,
            'coupon.currency_mismatch',
            fn (): Coupon => $this->validator->validateCode(
                $coupon->code,
                $this->context(Money::ofMinor(20_00, 'USD')),
            ),
        );
        $this->assertSame('KWD', $exception->context()['coupon_currency']);
        $this->assertSame('USD', $exception->context()['order_currency']);
    }

    #[Test]
    public function a_plan_outside_the_applicable_list_is_refused_with_its_own_error_code(): void
    {
        $covered = Plan::factory()->create();
        $other = Plan::factory()->create();

        $coupon = Coupon::factory()->forPlans([(string) $covered->id])->create();

        $exception = $this->assertRefusal(
            $coupon,
            CouponNotApplicableException::class,
            'coupon.not_applicable',
            fn (): Coupon => $this->validator->validateCode(
                $coupon->code,
                $this->context(planIds: [(string) $other->id]),
            ),
        );
        $this->assertSame('plan', $exception->context()['restriction']);

        // A basket made entirely of covered plans is accepted.
        $this->validator->validateCode($coupon->code, $this->context(planIds: [(string) $covered->id]));
    }

    #[Test]
    public function a_product_kind_outside_the_applicable_list_is_refused_with_its_own_error_code(): void
    {
        $coupon = Coupon::factory()->forProductKinds([ProductKind::Vps])->create();

        $exception = $this->assertRefusal(
            $coupon,
            CouponNotApplicableException::class,
            'coupon.not_applicable',
            fn (): Coupon => $this->validator->validateCode(
                $coupon->code,
                $this->context(productKinds: [ProductKind::Dedicated]),
            ),
        );
        $this->assertSame('product_kind', $exception->context()['restriction']);

        $this->validator->validateCode($coupon->code, $this->context(productKinds: [ProductKind::Vps]));
    }

    #[Test]
    public function a_restricted_coupon_is_refused_when_only_part_of_the_basket_is_covered(): void
    {
        $covered = Plan::factory()->create();
        $other = Plan::factory()->create();
        $coupon = Coupon::factory()->forPlans([(string) $covered->id])->create();

        // The discount is computed over the whole order, so a partial match
        // would spend campaign budget on the uncovered plan.
        $this->expectException(CouponNotApplicableException::class);
        $this->validator->validate(
            $coupon,
            $this->context(planIds: [(string) $covered->id, (string) $other->id]),
        );
    }

    #[Test]
    public function every_rejection_reason_reports_a_distinct_error_code(): void
    {
        $codes = [
            (UnknownCouponException::forCode('X'))->errorCode(),
            (CouponInactiveException::forCoupon('c', 'X'))->errorCode(),
            (CouponNotYetValidException::forCoupon('c', 'X', CarbonImmutable::now()))->errorCode(),
            (CouponExpiredException::forCoupon('c', 'X', CarbonImmutable::now()))->errorCode(),
            (CouponFullyRedeemedException::forCoupon('c', 'X', 1, 1))->errorCode(),
            (CouponCustomerLimitReachedException::forCustomer('c', 'X', 'cust', 1, 1))->errorCode(),
            (OrderBelowCouponMinimumException::forOrder('c', 'X', Money::zero('KWD'), Money::zero('KWD')))->errorCode(),
            (CouponCurrencyMismatchException::between('c', 'X', 'KWD', 'USD'))->errorCode(),
            (CouponNotApplicableException::forPlans('c', 'X', []))->errorCode(),
        ];

        $this->assertSame($codes, array_values(array_unique($codes)));
        $this->assertCount(9, $codes);

        foreach ($codes as $code) {
            $this->assertStringStartsWith('coupon.', $code);
        }
    }

    #[Test]
    public function code_matching_ignores_case_and_surrounding_whitespace(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'LAUNCH-25']);

        $pasted = [
            'LAUNCH-25',
            'launch-25',
            '  Launch-25  ',
            "\tlaunch-25\n",
            // A non-breaking space and a zero-width space, as pasted out of a
            // rendered email.
            "\u{00A0}LAUNCH-25\u{200B}",
        ];

        foreach ($pasted as $input) {
            $found = $this->validator->findByCode($input);

            $this->assertNotNull($found, sprintf('Expected "%s" to resolve.', addcslashes($input, "\0..\37")));
            $this->assertSame($coupon->id, $found->id);
        }

        $this->assertNull($this->validator->findByCode('   '));
        $this->assertNull($this->validator->findByCode('LAUNCH-26'));
    }

    #[Test]
    public function a_stored_code_in_mixed_case_is_still_matched(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'Summer-Sale']);

        $this->assertSame($coupon->id, $this->validator->resolveCode(' summer-sale ')->id);
    }

    #[Test]
    public function a_percentage_coupon_produces_an_exact_money_discount(): void
    {
        $coupon = Coupon::factory()->percentage('0.150000')->create();

        // 15% of 33.333 KWD is 4.99995, which is not representable in fils and
        // must round half-up to 5.000 exactly once.
        $discount = $this->validator->discountFor($coupon, Money::ofMinor(33_333, 'KWD'));

        $this->assertTrue($discount->equals(Money::ofMinor(5_000, 'KWD')));
        $this->assertSame('5.000', $discount->toDecimalString());
        $this->assertSame('KWD', $discount->currency());
    }

    #[Test]
    public function a_fixed_amount_coupon_produces_an_exact_money_discount(): void
    {
        $coupon = Coupon::factory()->fixed(5_000, 'KWD')->create();

        $discount = $this->validator->discountFor($coupon, Money::ofMinor(9_000, 'KWD'));

        $this->assertTrue($discount->equals(Money::ofMinor(5_000, 'KWD')));
        $this->assertSame('5.000', $discount->toDecimalString());
    }

    #[Test]
    public function a_coupon_worth_more_than_the_order_is_capped_at_the_order(): void
    {
        $coupon = Coupon::factory()->fixed(20_000, 'KWD')->create();

        $discount = $this->validator->discountFor($coupon, Money::ofMinor(9_000, 'KWD'));

        // Change is not given on a coupon: the total floors at zero rather
        // than turning into a debt the platform owes.
        $this->assertTrue($discount->equals(Money::ofMinor(9_000, 'KWD')));
    }

    #[Test]
    public function a_percentage_coupon_applies_to_any_currency(): void
    {
        $coupon = Coupon::factory()->percentage('0.100000')->create();

        $this->assertTrue(
            $this->validator->discountFor($coupon, Money::ofMinor(2_000, 'USD'))
                ->equals(Money::ofMinor(200, 'USD'))
        );
    }

    #[Test]
    public function a_valid_coupon_passes_every_check(): void
    {
        $coupon = Coupon::factory()
            ->limitedTo(100)
            ->perCustomer(1)
            ->minimumOrder(5_000)
            ->validBetween(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay())
            ->create();

        $this->validator->validate($coupon, $this->context());

        $this->assertTrue($this->validator->passes($coupon, $this->context()));
        $this->assertFalse($this->validator->passes($coupon, $this->context(Money::ofMinor(1_000, 'KWD'))));
    }

    /**
     * @param  list<string>  $planIds
     * @param  list<ProductKind>  $productKinds
     */
    private function context(
        ?Money $orderAmount = null,
        array $planIds = [],
        array $productKinds = [],
        ?CarbonImmutable $at = null,
    ): CouponContext {
        return new CouponContext(
            customer: $this->customer,
            orderAmount: $orderAmount ?? Money::ofMinor(9_000, 'KWD'),
            planIds: $planIds,
            productKinds: $productKinds,
            at: $at,
        );
    }

    /**
     * @template T of DomainException
     *
     * @param  class-string<T>  $expected
     * @param  (callable(): mixed)|null  $act
     * @return T
     */
    private function assertRefusal(Coupon $coupon, string $expected, string $errorCode, ?callable $act = null): DomainException
    {
        $act ??= fn (): Coupon => $this->validator->validateCode($coupon->code, $this->context());

        try {
            $act();
        } catch (DomainException $e) {
            $this->assertInstanceOf($expected, $e);
            $this->assertSame($errorCode, $e->errorCode());

            return $e;
        }

        $this->fail(sprintf('Expected %s to be thrown.', $expected));
    }
}
