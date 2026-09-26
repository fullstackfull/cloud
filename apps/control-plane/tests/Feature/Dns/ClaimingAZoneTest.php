<?php

declare(strict_types=1);

namespace Tests\Feature\Dns;

use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Enums\NoDerivedName;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Infrastructure\Application\Preflight\InfrastructurePreflightService;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightMode;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Holding a domain on the platform.
 *
 * The thing under test that is easiest to get wrong is what a claim *means*.
 * It is not verification and must never read as one — so these assert both
 * halves: the zone is created and is served by the provider, and the payload
 * says nothing that a customer could mistake for "we have checked that this
 * domain is yours".
 */
final class ClaimingAZoneTest extends DnsTestCase
{
    #[Test]
    public function a_claimed_zone_is_created_at_the_provider_and_reports_what_to_delegate(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $provider = $this->provider();

        $response = $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'example.test']);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'example.test')
            ->assertJsonPath('data.state', DnsState::Active->value)
            ->assertJsonPath('data.is_live', true);

        // The nameservers are the product. Without them the customer has
        // nothing to type into their registrar, and the zone serves nobody.
        $this->assertNotEmpty($response->json('data.nameservers'));

        $this->assertNotNull($provider->findZone('example.test'));
    }

    #[Test]
    public function nothing_in_the_payload_claims_the_domain_has_been_verified(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->provider();

        $response = $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'example.test']);

        /** @var array<string, mixed> $payload */
        $payload = $response->json('data');

        /*
         * A guard rather than a style rule. The platform cannot establish that
         * an account owns a domain — delegation at the registrar is what
         * settles it — so any field on this payload spelled "verified" would
         * be a claim nothing behind it supports, and a customer would rely on
         * it.
         */
        foreach (array_keys($payload) as $field) {
            $this->assertStringNotContainsString('verif', (string) $field);
            $this->assertStringNotContainsString('owner', (string) $field);
        }
    }

    #[Test]
    public function a_domain_already_held_on_the_platform_is_refused(): void
    {
        [$first, $owner] = $this->accountWithOwner();
        [$second, $other] = $this->accountWithOwner();
        $this->provider();

        $this->actingAs($owner)
            ->withHeaders($this->actingFor($first))
            ->postJson('/api/v1/dns/zones', ['name' => 'contested.test'])
            ->assertCreated();

        /*
         * DNS itself will not have two holders of one delegation, so neither
         * will the platform. The refusal is deliberately not "you may not":
         * an account whose domain somebody else claimed by mistake needs an
         * operator, and a 409 is what sends them to one.
         */
        $this->actingAs($other)
            ->withHeaders($this->actingFor($second))
            ->postJson('/api/v1/dns/zones', ['name' => 'contested.test'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dns.zone.already_claimed');
    }

    #[Test]
    public function the_platforms_own_names_and_their_parents_cannot_be_claimed(): void
    {
        config()->set('dns.reserved_zones', ['panel.lynomia.test']);

        [$customer, $owner] = $this->accountWithOwner();
        $this->provider();

        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'panel.lynomia.test'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'dns.zone.reserved');

        // The same attack one step out. An account holding lynomia.test can
        // serve panel.lynomia.test whatever this platform thinks it owns.
        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'lynomia.test'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'dns.zone.reserved');
    }

    #[Test]
    public function a_name_beneath_one_the_platform_holds_cannot_be_claimed_either(): void
    {
        config()->set('dns.reserved_zones', ['panel.lynomia.test']);

        [$customer, $owner] = $this->accountWithOwner();
        $provider = $this->provider();

        /*
         * The direction that was missing. The zone is created in the platform's
         * own provider account — the account that also holds the platform's
         * names — and records written into it are published there, so a claim
         * beneath a reserved name is a zone for the platform's name space that
         * the platform did not create and does not control.
         */
        foreach (['db.panel.lynomia.test', 'a.b.panel.lynomia.test'] as $name) {
            $this->actingAs($owner)
                ->withHeaders($this->actingFor($customer))
                ->postJson('/api/v1/dns/zones', ['name' => $name])
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'dns.zone.reserved');

            // Refused, not merely answered with a refusal: nothing reached
            // the provider and nothing was written here.
            $this->assertNull($provider->findZone($name), sprintf('%s was created at the provider.', $name));
            $this->assertFalse(DnsZone::query()->where('name', $name)->exists());
        }
    }

    #[Test]
    public function a_name_beside_a_reserved_one_is_still_anybodys_to_claim(): void
    {
        config()->set('dns.reserved_zones', ['panel.lynomia.test']);

        [$customer, $owner] = $this->accountWithOwner();
        $this->provider();

        /*
         * The twin. A sibling is not beneath the reserved name, and a name
         * that merely ends in the same characters is not either —
         * `notpanel.lynomia.test` is a different label, and a suffix match on
         * the string rather than on a label boundary would refuse it.
         */
        foreach (['api.lynomia.test', 'notpanel.lynomia.test', 'panel.lynomia.example'] as $name) {
            $this->actingAs($owner)
                ->withHeaders($this->actingFor($customer))
                ->postJson('/api/v1/dns/zones', ['name' => $name])
                ->assertCreated();
        }
    }

    #[Test]
    public function the_name_the_platform_answers_on_is_reserved_without_being_listed(): void
    {
        /*
         * Nothing configured, which is what the variable ships as. The
         * platform's own address is still known — it is APP_URL — so its host
         * is held, with its parents and everything beneath it.
         */
        config()->set('dns.reserved_zones', []);
        config()->set('app.url', 'https://panel.lynomia.test:8443/api');

        [$customer, $owner] = $this->accountWithOwner();
        $provider = $this->provider();

        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'lynomia.test'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'dns.zone.reserved')
            ->assertJsonPath('error.details.zone', 'lynomia.test');

        foreach (['panel.lynomia.test', 'db.panel.lynomia.test'] as $name) {
            $this->actingAs($owner)
                ->withHeaders($this->actingFor($customer))
                ->postJson('/api/v1/dns/zones', ['name' => $name])
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'dns.zone.reserved');
        }

        $this->assertNull($provider->findZone('lynomia.test'));
        $this->assertNull($provider->findZone('db.panel.lynomia.test'));
    }

    #[Test]
    public function the_name_the_portal_answers_on_is_reserved_without_being_listed(): void
    {
        config()->set('dns.reserved_zones', []);
        config()->set('app.frontend_url', 'https://portal.lynomia.test');

        [$customer, $owner] = $this->accountWithOwner();
        $this->provider();

        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'login.portal.lynomia.test'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'dns.zone.reserved');
    }

    #[Test]
    public function the_name_the_platform_answers_on_is_reserved_when_it_is_written_in_unicode(): void
    {
        /*
         * An operator writes an internationalised address the way people
         * read it. DNS never sees that form: a resolver is asked for the
         * A-label, and the A-label is what an account would type to claim
         * the name. So that is the form that is held — with its parents and
         * everything beneath it, like any other host.
         */
        config()->set('dns.reserved_zones', []);
        config()->set('app.url', 'https://münchen.lynomia.test');
        config()->set('app.frontend_url', 'http://localhost:5173');

        [$customer, $owner] = $this->accountWithOwner();
        $provider = $this->provider();

        foreach (['xn--mnchen-3ya.lynomia.test', 'lynomia.test', 'a.xn--mnchen-3ya.lynomia.test'] as $name) {
            $this->actingAs($owner)
                ->withHeaders($this->actingFor($customer))
                ->postJson('/api/v1/dns/zones', ['name' => $name])
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'dns.zone.reserved');

            $this->assertNull($provider->findZone($name), sprintf('%s was created at the provider.', $name));
        }
    }

    /**
     * An address that gave no name; what the preflight says of it, word for
     * word; and what a claim near it meets — true for refused, false for
     * created. The sentence is written out here rather than read from the enum
     * so that it sits beside the evidence that makes it true, and so that a
     * change to it cannot pass without somebody putting it back beside that
     * evidence.
     *
     * @return iterable<string, array{0: string, 1: NoDerivedName, 2: string, 3: array<string, bool>}>
     */
    public static function addressesThatContributeNothing(): iterable
    {
        yield 'unset' => [
            '', NoDerivedName::Unset,
            'it is not set, so it holds nothing',
            ['lynomia.test' => false],
        ];
        yield 'a host with no scheme in front of it' => [
            'panel.lynomia.test', NoDerivedName::NotAUrl,
            'no host could be read from it — a URL needs a scheme, such as https://, in front of the host — so it holds nothing',
            ['panel.lynomia.test' => false, 'lynomia.test' => false],
        ];
        yield 'an address' => [
            'http://203.0.113.10:8000', NoDerivedName::IpAddress,
            'its host is an IP address, and no zone at, above or beneath an address can be claimed, so there is nothing for it to hold',
            ['203.0.113.10' => true, '113.10' => true, 'panel.203.0.113.10' => true],
        ];
        yield 'a single label' => [
            'http://localhost:8000', NoDerivedName::SingleLabel,
            'its host is a single label, which cannot be claimed as a zone and has nothing above it; it holds none of the names beneath it',
            ['localhost' => true, 'panel.localhost' => false],
        ];
        yield 'a host the name rules refuse' => [
            'https://my_panel.lynomia.test', NoDerivedName::NotADomainName,
            'its host is not a name the zone rules accept, so it holds nothing: not the host, and nothing above or beneath it',
            ['my_panel.lynomia.test' => true, 'lynomia.test' => false],
        ];
    }

    /**
     * The preflight tells an operator why an address gave nothing, and each
     * reason says what that leaves open. Held against the guard here, because
     * a report can say "nothing there an account could claim" of a host with
     * no scheme in front of it, whose name any account can claim, and nothing
     * else would notice. Which names are open is asserted as well as which are
     * shut: the reason has to stay true in both directions, and a guard that
     * started holding more would need the report to say so.
     *
     * @param  array<string, bool>  $claims
     */
    #[Test]
    #[DataProvider('addressesThatContributeNothing')]
    public function what_the_preflight_says_of_an_address_that_gave_nothing_is_what_a_claim_meets(string $app, NoDerivedName $why, string $said, array $claims): void
    {
        config()->set('dns.reserved_zones', []);
        config()->set('app.url', $app);
        config()->set('app.frontend_url', 'https://portal.other.test');

        $this->provider();

        $summary = null;

        foreach (app(InfrastructurePreflightService::class)->run(PreflightRequest::estate(PreflightMode::Simulation))->findings as $finding) {
            if ($finding->id === 'dns.reserved_zones') {
                $summary = $finding->summary;
            }
        }

        $this->assertSame($said, $why->reason(), 'The reason changed; put the new sentence here, beside what a claim meets.');
        $this->assertIsString($summary);
        $this->assertStringStartsWith(sprintf('APP_URL contributed no name: %s.', $said), $summary);

        foreach ($claims as $name => $refused) {
            [$customer, $owner] = $this->accountWithOwner();

            $status = $this->actingAs($owner)
                ->withHeaders($this->actingFor($customer))
                ->postJson('/api/v1/dns/zones', ['name' => $name])
                ->status();

            $this->assertSame($refused, $status !== 201, sprintf(
                '%s was %s, and the preflight says: %s',
                $name,
                $status === 201 ? 'claimed' : 'refused',
                $summary,
            ));
        }
    }

    /**
     * Every reason is held against the guard above; one added without a row
     * there would be said to operators unchecked.
     */
    #[Test]
    public function every_reason_is_held_against_the_guard(): void
    {
        $covered = array_map(
            static fn (array $row): NoDerivedName => $row[1],
            array_values(iterator_to_array(self::addressesThatContributeNothing())),
        );

        foreach (NoDerivedName::cases() as $reason) {
            $this->assertContains($reason, $covered, sprintf('%s has no row in addressesThatContributeNothing().', $reason->name));
        }
    }

    #[Test]
    public function on_the_shipped_configuration_localhost_is_refused_as_a_name_and_nothing_is_derived_for_it(): void
    {
        /*
         * Why the derivation is allowed to come up empty here. `localhost` is
         * one label, and a zone of one label is refused by the name rules
         * before any reservation is consulted — so `localhost` itself cannot
         * be claimed, and reserving it would have meant relaxing those rules
         * for the one caller that needs them strictest. Names beneath it are
         * two labels and are not held; the estate preflight says so.
         */
        config()->set('dns.reserved_zones', []);
        config()->set('app.url', 'http://localhost:8000');
        config()->set('app.frontend_url', 'http://localhost:5173');

        [$customer, $owner] = $this->accountWithOwner();
        $this->provider();

        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'localhost'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'dns.invalid_name');

        // And an ordinary name is not caught by a reservation that is empty.
        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'localhost.test'])
            ->assertCreated();
    }

    #[Test]
    public function one_entry_in_the_reserved_list_that_is_not_a_name_refuses_every_claim(): void
    {
        /*
         * Fail closed, and loudly elsewhere. The whole list is read before
         * any comparison, so a list with a typo in it protects nothing it
         * could not read — and rather than guess, the guard refuses. That is
         * why the preflight reports this state as a failure: the guard is
         * correct and every customer is locked out until it is fixed.
         *
         * What the refusal *says* is deliberately not asserted here. It is
         * a validation error about a name the customer did not type, and a
         * row asserting its wording would bless it.
         */
        config()->set('dns.reserved_zones', ['lynomia.test', 'not a name']);

        [$customer, $owner] = $this->accountWithOwner();
        $provider = $this->provider();

        $response = $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'unrelated.test']);

        $this->assertNotSame(201, $response->status());
        $this->assertNull($provider->findZone('unrelated.test'));
        $this->assertFalse(DnsZone::query()->where('name', 'unrelated.test')->exists());
    }

    #[Test]
    public function an_entry_in_the_reserved_list_that_is_not_even_text_refuses_every_claim(): void
    {
        /*
         * `DNS_RESERVED_ZONES` always reads as a list of strings; an edited
         * `config/dns.php` need not. A nested value is an entry that does not
         * read like any other, and it fails the same way — closed — rather
         * than vanishing and protecting less than was written.
         */
        config()->set('dns.reserved_zones', [['lynomia.test']]);

        [$customer, $owner] = $this->accountWithOwner();
        $provider = $this->provider();

        $response = $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'lynomia.test']);

        $this->assertNotSame(201, $response->status());
        $this->assertNull($provider->findZone('lynomia.test'));
    }

    #[Test]
    public function reverse_zones_are_refused_because_they_are_a_different_capability(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->provider();

        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => '24.100.51.198.in-addr.arpa'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'dns.zone.is_reverse');
    }

    #[Test]
    public function a_name_that_is_not_a_name_is_refused_with_the_rule_it_broke(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->provider();

        foreach (['localhost', 'example..test', '-bad.test', 'xn--München.test'] as $name) {
            $this->actingAs($owner)
                ->withHeaders($this->actingFor($customer))
                ->postJson('/api/v1/dns/zones', ['name' => $name])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'dns.invalid_name');
        }
    }

    #[Test]
    public function a_member_who_may_only_look_cannot_claim_one(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $member = $this->memberOf($customer, CustomerRole::Member);
        $this->provider();

        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'readable.test'])
            ->assertCreated();

        $this->actingAs($member)
            ->withHeaders($this->actingFor($customer))
            ->getJson('/api/v1/dns/zones')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($member)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'other.test'])
            ->assertForbidden();
    }

    #[Test]
    public function another_accounts_zone_is_a_404_rather_than_a_403(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();
        $this->provider();

        $zone = DnsZone::factory()->active()->create(['customer_id' => $theirs->getKey()]);

        // Not 403: a 403 would confirm that this id names a zone, which is a
        // way of asking the platform who holds which domain.
        $this->actingAs($me)
            ->withHeaders($this->actingFor($mine))
            ->getJson('/api/v1/dns/zones/'.$zone->getKey())
            ->assertNotFound();
    }

    #[Test]
    public function claiming_a_zone_is_audited(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->provider();

        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'audited.test'])
            ->assertCreated();

        $entry = AuditEntry::query()->where('action', AuditAction::DnsZoneCreated->value)->firstOrFail();

        $this->assertSame('audited.test', $entry->context['zone'] ?? null);
        $this->assertSame((string) $customer->getKey(), $entry->customer_id);
    }

    #[Test]
    public function the_ceiling_on_zones_per_account_is_enforced(): void
    {
        config()->set('dns.zones_per_customer', 1);

        [$customer, $owner] = $this->accountWithOwner();
        $this->provider();

        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'first.test'])
            ->assertCreated();

        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'second.test'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dns.zone.limit_reached');
    }

    #[Test]
    public function a_provider_that_does_not_answer_leaves_the_zone_indeterminate_rather_than_failed(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->provider();

        /*
         * The Timeout Rule at the zone level. "Failed" would invite the
         * customer to claim the domain again, and a second create against a
         * provider that took the first is how one account ends up holding a
         * domain twice.
         */
        $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'dns-timeout.test'])
            ->assertCreated()
            ->assertJsonPath('data.state', DnsState::Indeterminate->value)
            ->assertJsonPath('data.needs_attention', true);
    }

    #[Test]
    public function a_provider_refusal_is_recorded_as_a_failure_with_its_secrets_removed(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->provider();

        $response = $this->actingAs($owner)
            ->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'dns-refused.test'])
            ->assertCreated()
            ->assertJsonPath('data.state', DnsState::Failed->value);

        $reason = (string) $response->json('data.failure_reason');

        // The fake quotes the request it sent, headers and all, because that
        // is what a real zone client does when it fails.
        $this->assertStringNotContainsString('fake-cloudflare-token', $reason);
    }
}
