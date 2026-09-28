<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Application\Actions\InviteMember;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * A team invitation stored before login addresses had one spelling is the
 * same address as that spelling (verifier of round eight, after a08802c).
 *
 * a08802c made `A` + U+0308 and `Ä` one login address, but left the rows
 * InviteMember had already written: mb_strtolower() without NFC, so an open
 * offer to a decomposed `ärger@…` stayed decomposed. A new offer to
 * `Ärger@…` was written composed, the partial unique index
 * customer_invitations_one_live_offer compared lower(email) and saw two
 * addresses, and the account held two live offers for one address — both
 * tokens accepted by AcceptInvitation, which compares normalised spellings.
 *
 * 2026_05_04_000121 rewrites every invitation's address into the one
 * spelling, refusing (and changing nothing) where two live offers of one
 * account are one address.
 */
final class ALegacyInvitationSpellingIsTheSameAddressTest extends TeamApiTestCase
{
    private const string DECOMPOSED = "a\u{0308}rger@lynomia.test";

    private const string COMPOSED = 'ärger@lynomia.test';

    private const string DECLINE_TOKEN = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    private const string SHOW_TOKEN = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    /**
     * The verifier's reproduction, after the migration has run over it.
     */
    #[Test]
    public function a_new_offer_to_another_spelling_of_a_legacy_open_offer_is_refused(): void
    {
        [$customer] = $this->accountWithOwner();
        $legacy = $this->offer($customer, 'legacy-token');
        $this->spellAs($legacy, self::DECOMPOSED);

        $this->invitationMigration()->up();

        try {
            app(InviteMember::class)->execute($customer, 'Ärger@lynomia.test', CustomerRole::Member);
            $this->fail('Two live offers for one address.');
        } catch (MembershipRefusedException $e) {
            $this->assertSame('membership.invitation_already_open', $e->errorCode());
        }

        $this->assertSame(1, CustomerInvitation::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(self::COMPOSED, $legacy->fresh()?->email);
    }

    /**
     * Every row is rewritten; offers that no longer hold the address's live
     * slot (accepted, declined, revoked) fold onto one spelling with a live
     * one without a refusal, because the partial index does not cover them.
     */
    #[Test]
    public function the_migration_rewrites_every_offer_and_folds_closed_ones(): void
    {
        [$customer] = $this->accountWithOwner();
        [$other] = $this->accountWithOwner();

        $live = $this->offer($customer, 't-live');
        $declined = $this->offer($customer, 't-declined', ['declined_at' => CarbonImmutable::now()->subDay()]);
        $revoked = $this->offer($customer, 't-revoked', ['revoked_at' => CarbonImmutable::now()->subDay()]);
        $elsewhere = $this->offer($other, 't-elsewhere');
        $this->spellAs($live, 'ÄRGER@lynomia.test');
        $this->spellAs($declined, self::DECOMPOSED);
        $this->spellAs($revoked, 'Ärger@LYNOMIA.test');
        $this->spellAs($elsewhere, "A\u{0308}RGER@lynomia.test");

        $this->invitationMigration()->up();

        foreach ([$live, $declined, $revoked, $elsewhere] as $offer) {
            $this->assertSame(self::COMPOSED, DB::table('customer_invitations')->where('id', $offer->id)->value('email'));
        }
    }

    /**
     * Two live offers of one account that are one address are not merged: the
     * migration names them and changes nothing, in either table.
     */
    #[Test]
    public function the_migration_refuses_two_live_offers_of_one_account_and_changes_nothing(): void
    {
        [$customer] = $this->accountWithOwner();

        $composed = $this->offer($customer, 't-one');
        $decomposed = $this->offer($customer, 't-two', ['expires_at' => CarbonImmutable::now()->subDay()]);
        $unrelated = $this->offer($customer, 't-three');
        $this->spellAs($composed, self::COMPOSED);
        $this->spellAs($decomposed, self::DECOMPOSED);
        $this->spellAs($unrelated, 'Somebody@lynomia.test');

        $before = DB::table('customer_invitations')->orderBy('id')->pluck('email', 'id')->all();

        try {
            $this->invitationMigration()->up();
            $this->fail('Two live offers for one address were merged or rewritten.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString((string) $composed->id, $e->getMessage());
            $this->assertStringContainsString((string) $decomposed->id, $e->getMessage());
            $this->assertStringNotContainsString((string) $unrelated->id, $e->getMessage());
        }

        $this->assertSame($before, DB::table('customer_invitations')->orderBy('id')->pluck('email', 'id')->all());
    }

    /**
     * The cooldown compares the stored spelling by equality: a declined offer
     * mailed a moment ago holds back a new one under any spelling.
     */
    #[Test]
    public function the_cooldown_sees_a_closed_offer_under_another_spelling(): void
    {
        config(['teams.invitation_cooldown_minutes' => 10]);
        [$customer] = $this->accountWithOwner();
        $this->offer($customer, 't-cool', ['declined_at' => CarbonImmutable::now(), 'email' => "A\u{0308}RGER@lynomia.test"]);

        try {
            app(InviteMember::class)->execute($customer, 'ärger@LYNOMIA.test', CustomerRole::Member);
            $this->fail('A declined offer mailed a moment ago did not hold back another spelling.');
        } catch (MembershipRefusedException $e) {
            $this->assertSame('membership.invitation_sent_too_recently', $e->errorCode());
        }
    }

    /**
     * A forwarded invitation is not declined by whoever it was forwarded to:
     * the offer stays open for the person it was made to.
     */
    #[Test]
    public function a_forwarded_invitation_cannot_be_declined_by_another_login(): void
    {
        [$customer] = $this->accountWithOwner();
        $offer = $this->offer($customer, self::DECLINE_TOKEN, ['email' => 'intended@lynomia.test']);
        $someoneElse = User::factory()->create(['email' => 'forwarded@lynomia.test', 'email_verified_at' => now()]);

        $this->actingAs($someoneElse)
            ->postJson('/api/v1/invitations/'.self::DECLINE_TOKEN.'/decline')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'membership.invitation_not_yours');

        $this->assertNull($offer->fresh()?->declined_at);

        // Positive control: the person it was made to, under another spelling.
        $intended = User::factory()->create(['email' => 'INTENDED@lynomia.test', 'email_verified_at' => now()]);
        $this->actingAs($intended)
            ->postJson('/api/v1/invitations/'.self::DECLINE_TOKEN.'/decline')
            ->assertNoContent();
        $this->assertNotNull($offer->fresh()?->declined_at);
    }

    /**
     * `is_for_you` is true for the person the offer was made to and false for
     * any other login holding the token.
     */
    #[Test]
    public function a_forwarded_invitation_is_not_shown_as_for_another_login(): void
    {
        [$customer] = $this->accountWithOwner();
        $this->offer($customer, self::SHOW_TOKEN, ['email' => "A\u{0308}RGER@lynomia.test"]);
        $someoneElse = User::factory()->create(['email' => 'forwarded@lynomia.test', 'email_verified_at' => now()]);
        $intended = User::factory()->create(['email' => 'ärger@lynomia.test', 'email_verified_at' => now()]);
        $url = '/api/v1/invitations/'.self::SHOW_TOKEN;

        $this->actingAs($someoneElse)->getJson($url)->assertOk()->assertJsonPath('data.is_for_you', false);
        $this->actingAs($intended)->getJson($url)->assertOk()->assertJsonPath('data.is_for_you', true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function offer(Customer $customer, string $token, array $attributes = []): CustomerInvitation
    {
        /** @var CustomerInvitation $offer */
        $offer = CustomerInvitation::factory()->withToken($token)->create(['customer_id' => $customer->id, ...$attributes]);

        return $offer;
    }

    /**
     * Writes a spelling the way code before the one spelling could have, past
     * the model.
     */
    private function spellAs(CustomerInvitation $offer, string $email): void
    {
        DB::table('customer_invitations')->where('id', $offer->id)->update(['email' => $email]);
    }

    private function invitationMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_05_04_000121_store_every_invitation_address_in_its_one_spelling.php');

        return $migration;
    }
}
