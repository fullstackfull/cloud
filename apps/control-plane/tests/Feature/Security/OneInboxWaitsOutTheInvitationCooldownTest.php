<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Mail\InvitationMail;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Team\TeamApiTestCase;

/**
 * One account cannot put its whole invitation budget into one inbox.
 *
 * The `team-invitations` limiter bounds how much an account sends in an hour
 * and nothing about where. Before the cooldown, an owner who invited one
 * address and pressed Resend in a loop sent that address thirty mails in well
 * under a second, every hour; withdrawing the offer and inviting the address
 * again was a second road to the same result, a fresh row and a fresh mail per
 * cycle. The audit's last clause on F-17 — "`ResendInvitation` has no
 * cooldown" — was that.
 *
 * Each road is driven over HTTP, inside the wait and after it, and the refusal
 * is checked for what it must NOT do as well as for its code: no mail, and on
 * a resend no new token, no new expiry and no count.
 *
 * **What this does not cover:** two requests racing each other. Both actions
 * compare under a row lock and say why. The withdraw-and-invite case pins
 * that InviteMember takes its lock; nothing here pins that ResendInvitation
 * compares the row it locked rather than the one it was handed, and nothing
 * can make two requests race, because PHPUnit runs one at a time.
 */
final class OneInboxWaitsOutTheInvitationCooldownTest extends TeamApiTestCase
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

        // Locked, not just read: a resend racing this commits its new
        // last_sent_at under the offer's row lock, and an unlocked read could
        // see the older time. This pins that the lock is taken; it cannot make
        // two requests race.
        $this->assertCount(1, $locks, 'InviteMember read the earlier offers to this address without locking them.');

        Mail::assertQueuedCount(1);
        $this->assertSame(1, CustomerInvitation::query()->where('customer_id', $customer->getKey())->count());

        $this->travelTo($mailedAt->addMinutes(10));

        $this->invite($owner, $customer)->assertCreated();
        Mail::assertQueuedCount(2);
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

    private function invite(User $user, Customer $customer): TestResponse
    {
        $this->startAFreshRequest();

        return $this->actingAs($user)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/team/invitations', ['email' => self::VICTIM, 'role' => CustomerRole::Member->value]);
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
