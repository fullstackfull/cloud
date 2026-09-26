<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Identity\Application\Actions\ResendInvitation;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Mail\InvitationMail;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Team\TeamApiTestCase;

/**
 * One account mails one address at most once per cooldown, whichever road the
 * mail takes.
 *
 * The `team-invitations` limiter bounds how much an account sends in an hour
 * and nothing about where. Before the cooldown, an owner who invited one
 * address and pressed Resend in a loop sent that address thirty mails an
 * hour, back to back, as fast as the requests arrived; withdrawing the offer
 * and inviting the address again was a second road to the same result, a
 * fresh row and a fresh mail per cycle. The audit's last clause on F-17 —
 * "`ResendInvitation` has no cooldown" — was that.
 *
 * Each road is driven over HTTP, inside the wait and after it, and the refusal
 * is checked for what it must NOT do as well as for its code: no mail, and on
 * a resend no new token, no new expiry and no count. The clock is the
 * address's last mail from this account: a resend restarts it, the invite
 * road reads it from every earlier offer to the address — the latest of them,
 * not the first — and an offer that was accepted or declined counts as much
 * as one that was withdrawn.
 *
 * **What this does not cover:** an inbox reached through several addresses.
 * `victim+1@` and `victim+2@` are two addresses to this code, each with a wait
 * of its own, and which addresses deliver to one mailbox is not something it
 * can know; the hourly budget is all that bounds those. Nor two requests
 * racing each other. Both actions compare under a row lock and say why. The
 * withdraw-and-invite case pins that InviteMember takes its lock, and
 * `a_resend_compares_the_row_it_locked_not_the_one_it_was_handed` pins that
 * ResendInvitation takes its lock and reads the time from the row it locked;
 * nothing can make two requests race, because PHPUnit runs one at a time.
 */
final class OneAddressWaitsOutTheInvitationCooldownTest extends TeamApiTestCase
{
    private const string VICTIM = 'victim@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['teams.invitation_cooldown_minutes' => 10]);
        $this->travelTo(CarbonImmutable::now()->startOfMinute());
    }

    #[Test]
    public function a_resend_inside_the_cooldown_is_refused_and_changes_nothing(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $mailedAt = CarbonImmutable::now();

        $offer = CustomerInvitation::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => self::VICTIM,
            'last_sent_at' => $mailedAt,
        ]);
        $before = $this->rowOf($offer);

        $this->travelTo($mailedAt->addMinutes(10)->subSecond());

        $this->resend($owner, $customer, $offer)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'membership.invitation_sent_too_recently')
            ->assertJsonPath('error.details.retry_at', $mailedAt->addMinutes(10)->toAtomString());

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertSame(
            $before,
            $this->rowOf($offer),
            'A refused resend wrote to the row. The token, the expiry, sent_count and last_sent_at must stay as they '
            .'were: a refusal that still rotated the token would kill the link the invitee already has.',
        );

        // The wait is ten minutes, not ten minutes and a bit.
        $this->travelTo($mailedAt->addMinutes(10));

        $this->resend($owner, $customer, $offer)
            ->assertOk()
            ->assertJsonPath('data.sent_count', 2);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(InvitationMail::class, static fn (InvitationMail $mail): bool => $mail->hasTo(self::VICTIM));
        $this->assertNotSame($before['token_hash'], $this->rowOf($offer)['token_hash']);
    }

    #[Test]
    public function a_resend_restarts_the_clock_so_a_loop_gets_one_mail_per_cooldown(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $offer = CustomerInvitation::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => self::VICTIM,
            'last_sent_at' => CarbonImmutable::now()->subHour(),
        ]);

        $statuses = [];

        for ($i = 0; $i < 5; $i++) {
            $statuses[] = $this->resend($owner, $customer, $offer)->status();
        }

        $this->assertSame([200, 409, 409, 409, 409], $statuses);
        Mail::assertQueuedCount(1);
    }

    #[Test]
    public function withdrawing_and_inviting_again_waits_for_the_same_clock(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $mailedAt = CarbonImmutable::now();

        $this->invite($owner, $customer)->assertCreated();
        $this->withdraw($owner, $customer);

        $this->travelTo($mailedAt->addMinutes(10)->subSecond());

        $locks = [];
        DB::listen(static function (QueryExecuted $query) use (&$locks): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'customer_invitations') && str_contains($sql, 'lower(email)') && str_contains($sql, 'for update')) {
                $locks[] = $sql;
            }
        });

        $this->invite($owner, $customer)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'membership.invitation_sent_too_recently');

        // Locked, not just read: read without the lock while a withdrawal of
        // the offer is still in flight, the offer would look open, be left out
        // of the comparison, and the insert would wait for the withdrawal to
        // commit and then succeed. This pins that the lock is taken; it cannot
        // make two requests race.
        $this->assertCount(1, $locks, 'InviteMember read the earlier offers to this address without locking them.');

        Mail::assertQueuedCount(1);
        $this->assertSame(1, CustomerInvitation::query()->where('customer_id', $customer->getKey())->count());

        $this->travelTo($mailedAt->addMinutes(10));

        $this->invite($owner, $customer)->assertCreated();
        Mail::assertQueuedCount(2);
    }

    /**
     * The second withdraw-and-invite waits for the second mail.
     *
     * After one cycle the address has two earlier offers: one whose wait is
     * over and one whose wait has only just begun. Reading the earliest of
     * them would reopen the loop after the first wait — every cycle from then
     * on compared against a mail long past — so the refusal names the later
     * one's time, and the address is mailed a third time only once it passes.
     */
    #[Test]
    public function a_repeated_withdraw_and_invite_waits_for_the_latest_mail_not_the_first(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $firstMailedAt = CarbonImmutable::now();

        $this->invite($owner, $customer)->assertCreated();
        $this->withdraw($owner, $customer);

        $secondMailedAt = $firstMailedAt->addMinutes(10);
        $this->travelTo($secondMailedAt);

        $this->invite($owner, $customer)->assertCreated();
        $this->withdraw($owner, $customer);

        $this->invite($owner, $customer)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'membership.invitation_sent_too_recently')
            ->assertJsonPath('error.details.retry_at', $secondMailedAt->addMinutes(10)->toAtomString());

        Mail::assertQueuedCount(2);
        $this->assertSame(2, CustomerInvitation::query()->where('customer_id', $customer->getKey())->count());

        $this->travelTo($secondMailedAt->addMinutes(10)->subSecond());
        $this->invite($owner, $customer)->assertStatus(409);
        Mail::assertQueuedCount(2);

        $this->travelTo($secondMailedAt->addMinutes(10));
        $this->invite($owner, $customer)->assertCreated();
        Mail::assertQueuedCount(3);
    }

    /**
     * A resend restarts the clock the invite road reads, not only its own.
     *
     * The offer is resent the moment its first wait is over and then withdrawn
     * straight away. The address was mailed just now, by the resend; inviting
     * it again is compared with that, not with when the offer was made.
     */
    #[Test]
    public function withdrawing_right_after_a_resend_waits_for_the_resend(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $madeAt = CarbonImmutable::now();

        $offer = CustomerInvitation::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => self::VICTIM,
            'last_sent_at' => $madeAt,
        ]);

        $resentAt = $madeAt->addMinutes(10);
        $this->travelTo($resentAt);

        $this->resend($owner, $customer, $offer)->assertOk();
        $this->withdraw($owner, $customer);

        $this->invite($owner, $customer)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'membership.invitation_sent_too_recently')
            ->assertJsonPath('error.details.retry_at', $resentAt->addMinutes(10)->toAtomString());

        Mail::assertQueuedCount(1);
    }

    /**
     * Accepted and declined offers have given up the address's slot as surely
     * as a withdrawn one, and the address was mailed all the same.
     *
     * Each was mailed five minutes ago. No member of the account has the
     * accepted one's address, as after the member it made has been removed,
     * and the declined one never made a member. Inviting either address
     * inside the wait is refused, nothing is queued and no row is written;
     * once the wait is over, both go.
     */
    #[Test]
    public function an_accepted_or_declined_offer_holds_its_address_to_the_wait(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $mailedAt = CarbonImmutable::now()->subMinutes(5);

        CustomerInvitation::factory()->withToken(str_repeat('a', 64))->create([
            'customer_id' => $customer->getKey(),
            'email' => 'accepted@example.test',
            'last_sent_at' => $mailedAt,
            'accepted_at' => CarbonImmutable::now()->subMinute(),
        ]);
        CustomerInvitation::factory()->withToken(str_repeat('b', 64))->create([
            'customer_id' => $customer->getKey(),
            'email' => 'declined@example.test',
            'last_sent_at' => $mailedAt,
            'declined_at' => CarbonImmutable::now()->subMinute(),
        ]);

        foreach (['accepted@example.test', 'declined@example.test'] as $address) {
            $this->invite($owner, $customer, $address)
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'membership.invitation_sent_too_recently')
                ->assertJsonPath('error.details.retry_at', $mailedAt->addMinutes(10)->toAtomString());
        }

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertSame(2, CustomerInvitation::query()->where('customer_id', $customer->getKey())->count());

        $this->travelTo($mailedAt->addMinutes(10));

        foreach (['accepted@example.test', 'declined@example.test'] as $address) {
            $this->invite($owner, $customer, $address)->assertCreated();
        }

        Mail::assertQueuedCount(2);
    }

    /**
     * The time is read from the row the resend locked, not from the copy it
     * was handed.
     *
     * In a request, the copy is what TeamController::invitationOfThisAccount()
     * loaded with a plain query before the resend's transaction began; here,
     * `$stale` stands for it. Another resend committing in between — here,
     * the row's `last_sent_at` moved to now behind the copy's back — leaves
     * the copy an hour stale; compared with that, the resend would go, and
     * the address would get two mails inside one wait. It must be refused,
     * and write nothing.
     *
     * Re-reading is not enough on its own: two resends that both re-read
     * before either writes would both see the old time. So the re-read is a
     * locking one, and this pins that the lock is taken — it cannot make two
     * requests race.
     */
    #[Test]
    public function a_resend_compares_the_row_it_locked_not_the_one_it_was_handed(): void
    {
        [$customer] = $this->accountWithOwner();

        $offer = CustomerInvitation::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => self::VICTIM,
            'last_sent_at' => CarbonImmutable::now()->subHour(),
        ]);

        /** @var CustomerInvitation $stale */
        $stale = CustomerInvitation::query()->findOrFail($offer->getKey());
        CustomerInvitation::query()->whereKey($offer->getKey())->update(['last_sent_at' => CarbonImmutable::now()]);
        $before = $this->rowOf($offer);

        $locks = [];
        DB::listen(static function (QueryExecuted $query) use (&$locks): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'customer_invitations') && str_contains($sql, 'for update')) {
                $locks[] = $sql;
            }
        });

        try {
            app(ResendInvitation::class)->execute($stale);
            $this->fail('A resend compared the copy it was handed, an hour stale, and mailed an address mailed just now.');
        } catch (MembershipRefusedException $refused) {
            $this->assertSame('membership.invitation_sent_too_recently', $refused->errorCode());
        }

        $this->assertCount(1, $locks, 'ResendInvitation re-read the offer without locking it, so two resends could both read the old last_sent_at.');
        $this->assertSame($before, $this->rowOf($offer));
    }

    /**
     * The clock is this account's, and another account can neither be held to
     * it nor learn from it that the address was just invited.
     *
     * The first account's offer is withdrawn before the second invites, so
     * that the second's check has a closed offer to the same address in
     * front of it — the case a clock shared across accounts would refuse.
     */
    #[Test]
    public function another_account_is_not_held_to_this_accounts_clock(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        [$elsewhere, $otherOwner] = $this->accountWithOwner();

        $this->invite($owner, $customer)->assertCreated();
        $this->withdraw($owner, $customer);

        $this->invite($otherOwner, $elsewhere)->assertCreated();
        $this->invite($owner, $customer)->assertStatus(409);

        Mail::assertQueuedCount(2);
    }

    #[Test]
    public function an_offer_that_recorded_no_send_waits_from_when_it_was_made(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $madeAt = CarbonImmutable::now();

        $offer = CustomerInvitation::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => self::VICTIM,
            'last_sent_at' => null,
        ]);

        $this->travelTo($madeAt->addMinutes(10)->subSecond());
        $this->resend($owner, $customer, $offer)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'membership.invitation_sent_too_recently');

        $this->travelTo($madeAt->addMinutes(10));
        $this->resend($owner, $customer, $offer)->assertOk();

        Mail::assertQueuedCount(1);
    }

    #[Test]
    public function a_cooldown_configured_as_zero_is_still_a_minute(): void
    {
        config(['teams.invitation_cooldown_minutes' => 0]);
        [$customer, $owner] = $this->accountWithOwner();
        $mailedAt = CarbonImmutable::now();

        $offer = CustomerInvitation::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => self::VICTIM,
            'last_sent_at' => $mailedAt,
        ]);

        $this->travelTo($mailedAt->addMinute()->subSecond());
        $this->resend($owner, $customer, $offer)->assertStatus(409);

        $this->travelTo($mailedAt->addMinute());
        $this->resend($owner, $customer, $offer)->assertOk();

        Mail::assertQueuedCount(1);
    }

    /**
     * The wait as shipped: ten minutes, which config/teams.php and
     * docs/teams.md both say.
     *
     * Every other test here sets the wait itself, so without this one the
     * default could fall to the one-minute floor with the suite still green.
     * It reads the default from the config file rather than from the booted
     * configuration, which setUp() has already overwritten, and refuses to
     * measure an environment that overrides it.
     */
    #[Test]
    public function the_wait_as_shipped_is_ten_minutes(): void
    {
        $this->assertNull(
            Env::get('TEAM_INVITATION_COOLDOWN_MINUTES'),
            'TEAM_INVITATION_COOLDOWN_MINUTES is set in this environment, so the value read below would be the '
            .'override and not the default this test is about.',
        );

        /** @var array<string, mixed> $shipped */
        $shipped = require config_path('teams.php');
        config(['teams.invitation_cooldown_minutes' => $shipped['invitation_cooldown_minutes']]);

        [$customer, $owner] = $this->accountWithOwner();
        $mailedAt = CarbonImmutable::now();

        $offer = CustomerInvitation::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => self::VICTIM,
            'last_sent_at' => $mailedAt,
        ]);

        $this->travelTo($mailedAt->addMinutes(10)->subSecond());
        $this->resend($owner, $customer, $offer)
            ->assertStatus(409)
            ->assertJsonPath('error.details.retry_at', $mailedAt->addMinutes(10)->toAtomString());

        $this->travelTo($mailedAt->addMinutes(10));
        $this->resend($owner, $customer, $offer)->assertOk();

        Mail::assertQueuedCount(1);
    }

    private function invite(User $user, Customer $customer, string $address = self::VICTIM): TestResponse
    {
        $this->startAFreshRequest();

        return $this->actingAs($user)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/invitations', ['email' => $address, 'role' => CustomerRole::Member->value]);
    }

    private function withdraw(User $user, Customer $customer): void
    {
        /** @var CustomerInvitation $offer */
        $offer = CustomerInvitation::query()
            ->where('customer_id', $customer->getKey())
            ->where('email', self::VICTIM)
            ->open()
            ->firstOrFail();

        $this->startAFreshRequest();

        $this->actingAs($user)
            ->withHeaders($this->actingFor($customer))
            ->deleteJson("/api/v1/team/invitations/{$offer->getKey()}")
            ->assertOk();
    }

    private function resend(User $user, Customer $customer, CustomerInvitation $offer): TestResponse
    {
        $this->startAFreshRequest();

        return $this->actingAs($user)
            ->withHeaders($this->actingFor($customer))
            ->postJson("/api/v1/team/invitations/{$offer->getKey()}/resend");
    }

    /**
     * The row as stored, so "unchanged" means every column, not the ones a
     * test thought to name.
     *
     * @return array<string, mixed>
     */
    private function rowOf(CustomerInvitation $offer): array
    {
        return (array) CustomerInvitation::query()->toBase()->where('id', $offer->getKey())->first();
    }

    /**
     * What a real request starts with: no acting customer, and no controller
     * still holding the previous request's. See the same helper in
     * TheInvitationLimiterIsAttachedWhereverTheMailIsSentTest.
     */
    private function startAFreshRequest(): void
    {
        $this->app->forgetScopedInstances();

        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }
    }
}
