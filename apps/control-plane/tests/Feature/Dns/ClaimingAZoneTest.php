<?php

declare(strict_types=1);

namespace Tests\Feature\Dns;

use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
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
