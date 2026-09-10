<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainContact;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a domain's own page reads, and what it is refused.
 *
 * Wave 3 gave a name an address of its own — `/domains/example.test` — which
 * means two things have to hold at the API. A name has to be resolvable by the
 * thing a customer recognises, and every one of those lookups has to be scoped
 * to the acting account, because a route parameter that is a domain name is a
 * route parameter anybody can type.
 *
 * Two capabilities the platform had and no screen called are also covered: the
 * auto-renew switch and the registrant read.
 */
final class ADomainHasItsOwnPageTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $owner;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        DomainTld::factory()->onSale()->named('test')->create();

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->owner = $this->memberOf($this->customer, CustomerRole::Owner);

        $this->domain = Domain::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'name' => 'mine.test',
            'tld' => 'test',
            'state' => DomainState::Active,
            'provider' => 'fake',
            'auto_renew' => true,
            'expires_at' => now()->addYear(),
        ]);
    }

    private function memberOf(Customer $customer, CustomerRole $role, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function acting(?Customer $customer = null): array
    {
        return ['X-Lynomia-Customer' => (string) ($customer ?? $this->customer)->getKey()];
    }

    #[Test]
    public function a_name_is_readable_by_the_name_a_customer_recognises(): void
    {
        // Both forms, and the same document either way: the portal addresses a
        // domain by its name because that is what a person reads out to
        // support, and the id keeps working for anything already using it.
        foreach ([$this->domain->name, (string) $this->domain->getKey()] as $identity) {
            $this->actingAs($this->owner)
                ->withHeaders($this->acting())
                ->getJson('/api/v1/domains/'.rawurlencode($identity))
                ->assertOk()
                ->assertJsonPath('data.name', 'mine.test');
        }
    }

    #[Test]
    public function the_name_is_matched_however_it_was_typed(): void
    {
        // A customer who types their own domain in capitals is not wrong.
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->getJson('/api/v1/domains/MINE.TEST')
            ->assertOk()
            ->assertJsonPath('data.name', 'mine.test');
    }

    #[Test]
    public function a_name_this_account_does_not_hold_is_not_found(): void
    {
        $theirs = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        Domain::factory()->create([
            'customer_id' => $theirs->getKey(),
            'name' => 'theirs.test',
            'tld' => 'test',
            'state' => DomainState::Active,
            'provider' => 'fake',
        ]);

        /*
         * The whole reason the lookup is scoped rather than filtered
         * afterwards: a name is guessable in a way a ULID is not, so the query
         * starts from the acting customer and another tenant's name is never
         * fetched at all. 404, not 403 — a 403 would confirm the name exists.
         */
        foreach (['theirs.test', 'nobody-holds-this.test'] as $identity) {
            $this->actingAs($this->owner)
                ->withHeaders($this->acting())
                ->getJson('/api/v1/domains/'.$identity)
                ->assertNotFound();
        }
    }

    #[Test]
    public function a_route_parameter_carrying_a_path_reaches_nothing(): void
    {
        /*
         * An encoded slash, a traversal and a name with a query string glued
         * on. The route pattern excludes slashes and the lookup is an equality
         * on a column, so none of these is a path the router will take or a
         * name the database holds — but they are the shapes somebody tries
         * first when a URL segment is suddenly a domain name.
         */
        foreach ([
            'mine.test%2F..%2Fadmin',
            '..%2F..%2Fetc%2Fpasswd',
            'mine.test%00',
            'mine.test%3Fexpand%3Dcontacts',
        ] as $attempt) {
            $response = $this->actingAs($this->owner)
                ->withHeaders($this->acting())
                ->getJson('/api/v1/domains/'.$attempt);

            $this->assertContains(
                $response->getStatusCode(),
                [404, 405],
                sprintf('%s must not reach a domain.', $attempt),
            );
        }
    }

    #[Test]
    public function auto_renew_can_be_turned_off_and_back_on_and_the_act_is_recorded(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->putJson('/api/v1/domains/mine.test/auto-renew', ['auto_renew' => false])
            ->assertOk()
            ->assertJsonPath('data.auto_renew', false);

        $this->assertFalse((bool) $this->domain->fresh()?->auto_renew);

        // Reversible in one request, which is why the screen asks for no typed
        // confirmation: this is a setting, not a destruction.
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->putJson('/api/v1/domains/mine.test/auto-renew', ['auto_renew' => true])
            ->assertOk()
            ->assertJsonPath('data.auto_renew', true);

        $recorded = AuditEntry::query()
            ->where('action', AuditAction::DomainAutoRenewChanged->value)
            ->count();

        $this->assertSame(2, $recorded);
    }

    #[Test]
    public function turning_auto_renew_off_does_not_end_anything(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->putJson('/api/v1/domains/mine.test/auto-renew', ['auto_renew' => false])
            ->assertOk();

        $after = $this->domain->fresh();

        // The name is still held, still active, and still expires when it
        // always did. Customers read "auto-renew off" as "cancelled", and the
        // platform must not make that true.
        $this->assertNotNull($after);
        $this->assertSame(DomainState::Active, $after->state);
        $this->assertNotNull($after->expires_at);
    }

    #[Test]
    public function a_name_the_platform_cannot_act_on_cannot_have_its_renewal_setting_changed(): void
    {
        // A registration the registrar never answered: the platform does not
        // know whether it holds the name at all.
        $held = Domain::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'name' => 'held.test',
            'tld' => 'test',
            'state' => DomainState::Indeterminate,
            'provider' => 'fake',
        ]);

        // 409: the platform will not promise to invoice for a renewal of a
        // name it cannot currently act on.
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->putJson('/api/v1/domains/'.$held->getKey().'/auto-renew', ['auto_renew' => false])
            ->assertStatus(409);
    }

    #[Test]
    public function the_registrant_is_read_back_so_a_correction_need_not_be_retyped(): void
    {
        DomainContact::factory()->create(['domain_id' => $this->domain->getKey()]);

        $response = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->getJson('/api/v1/domains/mine.test/contacts')
            ->assertOk();

        // Exactly the fields the update accepts, and the registrar's own
        // handle for the contact is not among them.
        $this->assertSame([
            'role', 'name', 'organisation', 'email', 'phone',
            'address_line_one', 'address_line_two', 'city', 'region',
            'postal_code', 'country', 'updated_at',
        ], array_keys((array) $response->json('data')));

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('provider_reference', $body);
    }

    #[Test]
    public function reading_the_registrant_needs_more_than_permission_to_look(): void
    {
        DomainContact::factory()->create(['domain_id' => $this->domain->getKey()]);

        // A person's name, home address and telephone number. The read-only
        // tier may see the account's services and is not owed those.
        $member = $this->memberOf($this->customer, CustomerRole::Member);

        $this->actingAs($member)
            ->withHeaders($this->acting())
            ->getJson('/api/v1/domains/mine.test/contacts')
            ->assertForbidden();
    }

    #[Test]
    public function a_name_with_no_registrant_on_record_says_so_rather_than_answering_blanks(): void
    {
        // A name transferred in before its contacts were read back has none,
        // and an empty object is what a form fills itself with blanks from.
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->getJson('/api/v1/domains/mine.test/contacts')
            ->assertNotFound();
    }

    #[Test]
    public function another_accounts_registrant_is_not_readable_by_name(): void
    {
        $theirs = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $theirDomain = Domain::factory()->create([
            'customer_id' => $theirs->getKey(),
            'name' => 'theirs.test',
            'tld' => 'test',
            'state' => DomainState::Active,
            'provider' => 'fake',
        ]);
        DomainContact::factory()->create(['domain_id' => $theirDomain->getKey()]);

        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->getJson('/api/v1/domains/theirs.test/contacts')
            ->assertNotFound();

        // And acting as a member of that account does not reach it either
        // through this login's own customer header.
        $this->actingAs($this->owner)
            ->withHeaders($this->acting($theirs))
            ->getJson('/api/v1/domains/theirs.test/contacts')
            ->assertForbidden();
    }
}
