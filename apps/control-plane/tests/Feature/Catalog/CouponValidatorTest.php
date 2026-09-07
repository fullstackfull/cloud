<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    public function a_basket_whose_kinds_arrive_as_strings_is_judged_the_same_way(): void
    {
        // This is the shape the checkout actually hands over: it reads the kind
        // off the catalogue and flattens it before building the context. A
        // validator that insisted on enum cases here blew up with a TypeError
        // — a 500 on every checkout using a kind-restricted campaign, instead
        // of either a discount or a refusal.
        $coupon = Coupon::factory()->forProductKinds([ProductKind::Vps])->create();

        $this->validator->validate($coupon, $this->context(productKinds: [ProductKind::Vps->value]));

        $exception = $this->assertRefusal(
            $coupon,
            CouponNotApplicableException::class,
            'coupon.not_applicable',
            fn (): Coupon => $this->validator->validateCode(
                $coupon->code,
                $this->context(productKinds: [ProductKind::Dedicated->value]),
            ),
        );
        $this->assertSame(ProductKind::Dedicated->value, $exception->context()['offending_product_kinds']);
    }

    #[Test]
    public function a_kind_the_enum_does_not_recognise_never_matches_a_restricted_campaign(): void
    {
        $coupon = Coupon::factory()->forProductKinds([ProductKind::Vps])->create();

        $this->assertRefusal(
            $coupon,
            CouponNotApplicableException::class,
            'coupon.not_applicable',
            fn (): Coupon => $this->validator->validateCode(
                $coupon->code,
                $this->context(productKinds: ['quantum_widget']),
            ),
        );
    }

    #[Test]
    public function a_restriction_the_enum_has_forgotten_still_restricts(): void
    {
        // A kind renamed or retired after the campaign was created. Filtering
        // the stored list through ProductKind::tryFrom() would empty it, and an
        // empty list means "anything" — so one stale string would turn a
        // campaign aimed at one product line into a discount on the catalogue.
        $coupon = Coupon::factory()->create(['applicable_product_kinds' => ['quantum_widget']]);

        $this->assertFalse($this->passes($coupon, $this->context(productKinds: [ProductKind::Vps])));
        $this->assertTrue($this->passes($coupon, $this->context(productKinds: ['quantum_widget'])));
    }

    #[Test]
    public function a_plan_restriction_stored_in_an_unexpected_shape_still_restricts(): void
    {
        $covered = Plan::factory()->create();

        // Written by an import that stored ids as numbers rather than strings.
        $coupon = Coupon::factory()->create(['applicable_plan_ids' => [12345]]);

        $this->assertFalse($this->passes($coupon, $this->context(planIds: [(string) $covered->id])));
        $this->assertTrue($this->passes($coupon, $this->context(planIds: ['12345'])));
    }

    #[Test]
    public function a_pasted_code_resolves_to_the_same_coupon_every_time(): void
    {
        // The unique index is on the column verbatim, so two codes differing
        // only in case can coexist and both match upper(code). Resolution must
        // not depend on the order the planner happens to return them in.
        $late = Coupon::factory()->create(['code' => 'SAVE10']);

        // Inserted second but keyed first, so an unordered scan hands back the
        // other row and only an explicit ordering picks this one.
        $early = '01AAAAAAAAAAAAAAAAAAAAAAAA';
        DB::table('coupons')->insert([
            'id' => $early,
            'code' => 'save10',
            'discount_type' => 'percentage',
            'percentage' => '0.100000',
            'applies_to_renewals' => false,
            'max_redemptions_per_customer' => 1,
            'redemption_count' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($early < $late->id);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->assertSame($early, $this->validator->resolveCode(' Save10 ')->id);
        }
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

        $this->assertTrue($this->passes($coupon, $this->context()));
        $this->assertFalse($this->passes($coupon, $this->context(Money::ofMinor(1_000, 'KWD'))));
    }

    /**
     * Whether the coupon would be accepted, without raising.
     *
     * A test convenience, and it lives here rather than on the validator:
     * production code always wants the exception — a coupon that cannot be
     * used has a reason, and a boolean throws it away — so a non-throwing
     * predicate in the domain service was a method nothing called.
     */
    private function passes(Coupon $coupon, CouponContext $context): bool
    {
        try {
            $this->validator->validate($coupon, $context);

            return true;
        } catch (DomainException) {
            return false;
        }
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
