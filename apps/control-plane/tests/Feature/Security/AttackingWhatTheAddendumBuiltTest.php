<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Identity\Domain\Enums\CountryCurrencyChangeState;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\CountryCurrencyChange;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteKind;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSiteOperation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Dns\DnsTestCase;

/**
 * The adversarial pass over what the scope addendum built, in one place.
 *
 * Each whole-life suite already refuses the obvious misuse of its own
 * surface. This suite is the second reader: the inputs somebody hostile
 * would try first — a file built to exhaust the parser, a link with the
 * token bent, a staging copy re-parented onto somebody else's site, a
 * setting written through a route that was never meant to carry it — and
 * the answer each has to be. Every case here writes nothing.
 */
final class AttackingWhatTheAddendumBuiltTest extends DnsTestCase
{
    private Customer $mine;

    private User $me;

    private Customer $theirs;

    private User $them;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->mine, $this->me] = $this->accountWithOwner();
        [$this->theirs, $this->them] = $this->accountWithOwner();
        $this->provider();
    }

    /* ---------------------------------------------------------------------
     | DNS zone import
     */

    #[Test]
    public function a_zone_file_built_to_exhaust_the_parser_is_refused_before_it_is_read(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $zone = $this->zoneOf($this->mine, $this->me, 'mine.test');

        foreach ([
            'too many lines' => str_repeat("www IN A 203.0.113.1\n", 5_001),
            'too many bytes' => str_repeat('; '.str_repeat('x', 100)."\n", 2_700),
            'a line too long' => 'www IN TXT "'.str_repeat('a', 4_100)."\"\n",
            'a NUL in the middle' => "www IN\0A 203.0.113.1\n",
            'an escape sequence' => "www IN A 203.0.113.1\x1b[2J\n",
            'not UTF-8' => "www IN TXT \"\xff\xfe\"\n",
        ] as $case => $text) {
            $response = $this->as($this->me, $this->mine)->postJson('/api/v1/dns/zones/'.$zone.'/import/plan', ['text' => $text]);
            $this->assertContains($response->status(), [413, 422], $case.' answered '.$response->status());
            $code = (string) $response->json('error.code');
            $this->assertTrue($code === 'validation.failed' || str_starts_with($code, 'dns.zone_file.'), $case.' answered '.$code);
        }

        // A file the parser can read but the zone cannot hold: refused as a plan, nothing written.
        $lines = '';
        for ($i = 0; $i < 300; $i++) {
            $lines .= sprintf("h%d IN A 203.0.113.%d\n", $i, ($i % 200) + 1);
        }
        $plan = $this->as($this->me, $this->mine)->postJson('/api/v1/dns/zones/'.$zone.'/import/plan', ['text' => $lines])->assertOk();
        $this->assertFalse($plan->json('data.applicable'));
        $this->assertStringContainsString('at most', implode(' ', array_column($plan->json('data.entries'), 'reason')));

        // The directives that reach outside the file are refused by name.
        $plan = $this->as($this->me, $this->mine)->postJson('/api/v1/dns/zones/'.$zone.'/import/plan', [
            'text' => "\$INCLUDE /etc/passwd\n\$GENERATE 1-1000 h\$ IN A 203.0.113.1\n\$ORIGIN evil.test.\nwww IN A 203.0.113.1\n",
        ])->assertOk();
        $reasons = implode(' | ', array_column($plan->json('data.entries'), 'reason'));
        $this->assertStringContainsString('$INCLUDE', $reasons);
        $this->assertStringContainsString('$GENERATE', $reasons);
        $this->assertStringContainsString('$ORIGIN must be', $reasons);
        $this->assertSame(0, $this->as($this->me, $this->mine)->getJson('/api/v1/dns/zones/'.$zone.'/records')->json('meta.total'));
    }

    #[Test]
    public function a_zone_import_cannot_be_aimed_at_another_accounts_zone_or_applied_with_a_fingerprint_it_did_not_preview(): void
    {
        $theirs = $this->zoneOf($this->theirs, $this->them, 'theirs.test');
        $mine = $this->zoneOf($this->mine, $this->me, 'mine.test');

        $this->as($this->me, $this->mine)->postJson('/api/v1/dns/zones/'.$theirs.'/import/plan', ['text' => "www IN A 203.0.113.1\n"])->assertNotFound();
        $this->as($this->me, $this->mine)->getJson('/api/v1/dns/zones/'.$theirs.'/export')->assertNotFound();

        // A fingerprint from a preview of a different file, or from a different zone.
        $other = $this->as($this->me, $this->mine)->postJson('/api/v1/dns/zones/'.$mine.'/import/plan', ['text' => "api IN A 203.0.113.2\n"])->json('data.fingerprint');
        $this->as($this->me, $this->mine)
            ->postJson('/api/v1/dns/zones/'.$mine.'/import', ['text' => "www IN A 203.0.113.1\n", 'fingerprint' => $other])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dns.import.plan_changed');

        $this->as($this->me, $this->mine)
            ->postJson('/api/v1/dns/zones/'.$mine.'/import', ['text' => "www IN A 203.0.113.1\n", 'fingerprint' => str_repeat('0', 64)])
            ->assertStatus(409);

        // A member who may look but not manage cannot preview: a plan reads every record.
        $viewer = $this->memberOf($this->mine, CustomerRole::Member);
        $this->as($viewer, $this->mine)->postJson('/api/v1/dns/zones/'.$mine.'/import/plan', ['text' => "www IN A 203.0.113.1\n"])->assertForbidden();

        $this->assertSame(0, $this->as($this->me, $this->mine)->getJson('/api/v1/dns/zones/'.$mine.'/records')->json('meta.total'));
    }

    /* ---------------------------------------------------------------------
     | Backup download links
     */

    #[Test]
    public function a_download_link_with_its_token_bent_is_not_a_link(): void
    {
        foreach ([
            str_repeat('a', 63),
            str_repeat('A', 64),
            str_repeat('a', 64).'/',
            str_repeat('a', 64).'%00',
            '../'.str_repeat('a', 61),
        ] as $token) {
            $this->as($this->me, $this->mine)->get('/api/v1/backups/downloads/'.$token)->assertNotFound();
        }
    }

    /* ---------------------------------------------------------------------
     | The account's country and currency
     */

    #[Test]
    public function the_profile_route_does_not_carry_the_currency_and_a_decision_cannot_be_replayed(): void
    {
        $this->seed(RolePermissionSeeder::class);
        PlanPrice::factory()->currency('USD', 1_000)->create();

        // The only write to those columns is the applying action.
        $this->as($this->me, $this->mine)->patchJson('/api/v1/me', ['name' => 'Someone', 'currency' => 'USD', 'country' => 'SA'])->assertOk();
        $this->assertSame('KWD', $this->mine->refresh()->currency);
        $this->assertSame('KW', $this->mine->country);

        $changeId = (string) $this->as($this->me, $this->mine)
            ->postJson('/api/v1/account/country-currency-changes', ['country' => 'KW', 'currency' => 'USD', 'reason' => 'Prefer USD.'])
            ->assertCreated()
            ->json('data.id');

        $operator = User::factory()->create();
        $operator->syncRoles([Role::SuperAdmin->value]);

        $this->actingAs($operator)->postJson('/api/admin/customers/country-currency-changes/'.$changeId.'/approve', ['note' => 'Fine.'])->assertOk();
        $this->assertSame('USD', $this->mine->refresh()->currency);

        // Applied is settled: approving again, rejecting, withdrawing all refuse.
        $this->actingAs($operator)->postJson('/api/admin/customers/country-currency-changes/'.$changeId.'/approve', ['note' => 'Again.'])->assertStatus(409);
        $this->actingAs($operator)->postJson('/api/admin/customers/country-currency-changes/'.$changeId.'/reject', ['note' => 'No.'])->assertStatus(409);
        $this->as($this->me, $this->mine)->postJson('/api/v1/account/country-currency-changes/'.$changeId.'/withdraw')->assertStatus(409);

        // A moment in the past is not a schedule.
        $second = (string) $this->as($this->me, $this->mine)
            ->postJson('/api/v1/account/country-currency-changes', ['country' => 'SA', 'currency' => 'USD', 'reason' => 'Moved.'])
            ->assertCreated()
            ->json('data.id');
        $this->actingAs($operator)
            ->postJson('/api/admin/customers/country-currency-changes/'.$second.'/approve', ['note' => 'Backdated.', 'apply_at' => now()->subDay()->toIso8601String()])
            ->assertStatus(422);
        $this->assertSame(CountryCurrencyChangeState::AwaitingApproval, CountryCurrencyChange::query()->findOrFail($second)->state);

        // Another account's request is not the operator's customer's to see through the customer route.
        $this->as($this->them, $this->theirs)->getJson('/api/v1/account/country-currency-changes')->assertOk()->assertJsonCount(0, 'data');
    }

    /* ---------------------------------------------------------------------
     | WordPress copies and pushes
     */

    #[Test]
    public function a_staging_copy_re_parented_onto_somebody_elses_site_cannot_push_over_it(): void
    {
        $this->app->singleton(HostingProviderFactory::class);
        $node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        $theirProduction = $this->liveSite($this->theirs, $node, 'victim.test');
        $myProduction = $this->liveSite($this->mine, $node, 'mine-wp.test');

        // My staging copy, with its parent pointed at their production site.
        $forged = WordPressSite::factory()->live()->create([
            'customer_id' => $this->mine->id,
            'hosting_account_id' => $myProduction->hosting_account_id,
            'domain' => 'staging.mine-wp.test',
            'kind' => WordPressSiteKind::Staging,
            'parent_site_id' => $theirProduction->id,
        ]);

        $this->as($this->me, $this->mine)
            ->getJson('/api/v1/wordpress/sites/'.$forged->id.'/push/impact')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'wordpress.production_gone');

        $this->as($this->me, $this->mine)
            ->postJson('/api/v1/wordpress/sites/'.$forged->id.'/push', ['confirmation' => 'victim.test'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'wordpress.production_gone');

        // A clone onto a name another account already serves is refused.
        $this->as($this->me, $this->mine)
            ->postJson('/api/v1/wordpress/sites/'.$myProduction->id.'/clones', ['domain' => 'victim.test'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'wordpress.domain_in_use');

        // The forged copy also occupies the name a real staging copy would take: refused by name, not by the index.
        $this->as($this->me, $this->mine)
            ->postJson('/api/v1/wordpress/sites/'.$myProduction->id.'/staging')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'wordpress.domain_in_use');

        // The staging domain typed instead of the production domain is not a confirmation.
        $forged->update(['parent_site_id' => $myProduction->id]);
        $this->as($this->me, $this->mine)
            ->postJson('/api/v1/wordpress/sites/'.$forged->id.'/push', ['confirmation' => 'staging.mine-wp.test'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'wordpress.push_confirmation_mismatch');

        $this->assertSame(0, WordPressSiteOperation::query()->where('kind', 'push_to_production')->count());
        $this->assertNotNull($theirProduction->refresh()->verified_at);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     */

    private function as(User $user, Customer $customer): self
    {
        return $this->actingAs($user)->withHeaders($this->actingFor($customer));
    }

    private function zoneOf(Customer $customer, User $owner, string $name): string
    {
        return (string) $this->as($owner, $customer)->postJson('/api/v1/dns/zones', ['name' => $name])->assertCreated()->json('data.id');
    }

    private function liveSite(Customer $customer, HostingNode $node, string $domain): WordPressSite
    {
        $account = HostingAccount::factory()->named('a'.substr(md5($domain), 0, 7))->create([
            'customer_id' => $customer->id,
            'hosting_node_id' => $node->id,
            'primary_domain' => $domain,
        ]);

        return WordPressSite::factory()->live()->create([
            'customer_id' => $customer->id,
            'hosting_account_id' => $account->id,
            'domain' => $domain,
        ]);
    }
}
