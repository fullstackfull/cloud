<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The invitation limiter is the only thing bounding outbound mail to
 * addresses a customer chooses, and `ResendInvitation` deliberately has no
 * cooldown of its own, so this bucket is the whole control.
 *
 * It used to be keyed on the raw `X-Lynomia-Customer` request header. The
 * fallback to the user fired only when the header was ABSENT, never when it
 * was present and unusable - so a caller who sent junk still acted on their
 * own account (ResolveActingCustomer treats an unparseable value as absent)
 * while presenting a bucket key nobody had ever used. That is not a rotated
 * limit, it is an unbounded one: every distinct value is a fresh budget.
 *
 * The limiter runs AFTER `ResolveActingCustomer` on both invitation routes —
 * attached through `ThrottleAfterAccountResolution`, because the plain
 * `throttle:` alias is sorted ahead of it — so the resolved account is
 * available to the limiter and there is no reason to consult the header at
 * all. This class sets the account itself and calls the closure directly, so
 * it pins the key and cannot see attachment or order;
 * TheInvitationLimiterIsAttachedWhereverTheMailIsSentTest does.
 */
final class TheInvitationLimiterCannotBeRotatedByAHeaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function junk_in_the_header_does_not_buy_a_fresh_bucket(): void
    {
        [$user, $customer] = $this->member();

        $baseline = $this->bucketFor($user, $customer, null);

        foreach (['junk-1', 'junk-2', 'NOT-A-ULID', '../../etc/passwd', str_repeat('x', 200)] as $junk) {
            $this->assertSame(
                $baseline,
                $this->bucketFor($user, $customer, $junk),
                "A header value of \"{$junk}\" moved the bucket, which is a fresh budget."
            );
        }
    }

    #[Test]
    public function case_folding_the_callers_own_identifier_does_not_buy_a_fresh_bucket(): void
    {
        [$user, $customer] = $this->member();
        $id = (string) $customer->getKey();

        $this->assertSame(
            $this->bucketFor($user, $customer, $id),
            $this->bucketFor($user, $customer, strtoupper($id)),
            'Crockford base32 is case-insensitive and the resolver lower-cases it; '
            .'the limiter must not treat the two spellings as different accounts.'
        );
    }

    #[Test]
    public function the_bucket_still_separates_two_different_accounts(): void
    {
        [$userA, $customerA] = $this->member();
        [$userB, $customerB] = $this->member();

        $this->assertNotSame(
            $this->bucketFor($userA, $customerA, null),
            $this->bucketFor($userB, $customerB, null),
            'Pinning the key must not collapse every account into one shared budget.'
        );
    }

    /**
     * @return array{0: User, 1: Customer}
     */
    private function member(): array
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);

        return [$user, $customer];
    }

    /**
     * The bucket key the registered limiter would use for this request.
     *
     * The closure is invoked directly rather than through the HTTP stack
     * because the key, not the 429, is the property under test.
     */
    private function bucketFor(User $user, Customer $customer, ?string $header): string
    {
        app(ActingCustomer::class)->set($customer);

        $request = Request::create('/api/v1/team/invitations', 'POST');
        $request->setUserResolver(static fn (): User => $user);

        if ($header !== null) {
            $request->headers->set('X-Lynomia-Customer', $header);
        }

        $limiter = RateLimiter::limiter('team-invitations');
        $this->assertNotNull($limiter, 'The team-invitations limiter is not registered.');

        return (string) $limiter($request)->key;
    }
}
