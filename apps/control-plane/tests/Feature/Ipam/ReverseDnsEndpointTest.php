<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Ipam\Application\Jobs\PublishReverseDnsRecord;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SecretFixtures;

/**
 * PUT /api/v1/ips/{assignment}/rdns.
 *
 * The one endpoint on this module that writes anything, and the only place a
 * customer-supplied string on this surface leaves the platform. Everything here
 * is about what happens to that string on the way out: it is validated as a
 * hostname before anything else looks at it, it is never sent from inside the
 * request, and an address that is not the caller's cannot be named at all.
 */
final class ReverseDnsEndpointTest extends IpamApiTestCase
{
    #[Test]
    public function it_records_the_hostname_and_hands_the_publishing_to_a_worker(): void
    {
        [$customer, $user] = $this->accountWith();
        $assignment = $this->assignmentFor($customer, address: '203.0.113.40');

        $this->actingAs($user)
            ->putJson('/api/v1/ips/'.$assignment->id.'/rdns', ['hostname' => 'mail.example.com'])
            // 202: recorded, not published. A 200 would read as "this is live
            // now", which is exactly what the platform cannot promise before
            // the zone API has been asked.
            ->assertStatus(202)
            ->assertJsonPath('data.reverse_dns.hostname', 'mail.example.com')
            ->assertJsonPath('data.reverse_dns.status', ReverseDnsStatus::Pending->value)
            ->assertJsonPath('meta.published', false);

        $this->assertDatabaseHas('reverse_dns_records', [
            'ip_address_id' => $assignment->ip_address_id,
            'hostname' => 'mail.example.com',
            'status' => ReverseDnsStatus::Pending->value,
        ]);

        Queue::assertPushed(PublishReverseDnsRecord::class);
    }

    #[Test]
    public function a_hostname_is_stored_in_one_canonical_form(): void
    {
        [$customer, $user] = $this->accountWith();
        $assignment = $this->assignmentFor($customer);

        // "Mail.Example.COM." and "mail.example.com" are one name. Storing both
        // spellings would be two records that disagree about one address.
        $this->actingAs($user)
            ->putJson('/api/v1/ips/'.$assignment->id.'/rdns', ['hostname' => '  Mail.Example.COM.  '])
            ->assertStatus(202)
            ->assertJsonPath('data.reverse_dns.hostname', 'mail.example.com');
    }

    #[Test]
    public function repeating_the_request_replaces_the_record_rather_than_adding_one(): void
    {
        [$customer, $user] = $this->accountWith();
        $assignment = $this->assignmentFor($customer);

        foreach (['one.example.com', 'two.example.com', 'two.example.com'] as $hostname) {
            $this->actingAs($user)
                ->putJson('/api/v1/ips/'.$assignment->id.'/rdns', ['hostname' => $hostname])
                ->assertStatus(202);
        }

        // One address, one PTR. No idempotency key is needed to make that true:
        // the unique index and the verb already are.
        $this->assertSame(1, ReverseDnsRecord::query()
            ->where('ip_address_id', $assignment->ip_address_id)
            ->count());

        $this->assertSame('two.example.com', ReverseDnsRecord::query()
            ->where('ip_address_id', $assignment->ip_address_id)
            ->value('hostname'));
    }

    #[Test]
    public function a_new_hostname_clears_the_previous_attempts_error(): void
    {
        [$customer, $user] = $this->accountWith();
        $assignment = $this->assignmentFor($customer);

        ReverseDnsRecord::factory()->create([
            'ip_address_id' => $assignment->ip_address_id,
            'hostname' => 'refused.example.com',
            'status' => ReverseDnsStatus::Failed,
            'last_error' => 'PATCH /zones/rdns 401',
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/ips/'.$assignment->id.'/rdns', ['hostname' => 'fresh.example.com'])
            ->assertStatus(202);

        $record = ReverseDnsRecord::query()->where('ip_address_id', $assignment->ip_address_id)->sole();

        // The old refusal belonged to the old name. Carried forward, it would
        // attach a provider's objection to a hostname it never saw.
        $this->assertSame(ReverseDnsStatus::Pending, $record->status);
        $this->assertNull($record->last_error);
    }

    #[Test]
    public function another_customers_address_cannot_be_named(): void
    {
        [, $user] = $this->accountWith();
        [$theirs] = $this->accountWith();

        $hers = $this->assignmentFor($theirs);

        $this->actingAs($user)
            ->putJson('/api/v1/ips/'.$hers->id.'/rdns', ['hostname' => 'attacker.example.com'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        // Nothing was written, and nothing was queued: a PTR naming this caller
        // must not appear on an address that is not theirs.
        $this->assertDatabaseCount('reverse_dns_records', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_invented_address_id_is_answered_identically(): void
    {
        [, $user] = $this->accountWith();
        [$theirs] = $this->accountWith();
        $hers = $this->assignmentFor($theirs);

        $body = ['hostname' => 'probe.example.com'];

        $real = $this->actingAs($user)->putJson('/api/v1/ips/'.$hers->id.'/rdns', $body);
        $invented = $this->actingAs($user)->putJson('/api/v1/ips/'.Str::ulid().'/rdns', $body);

        $this->assertSame($real->status(), $invented->status());
        $this->assertSame($real->json('error.code'), $invented->json('error.code'));
        $this->assertSame($real->json('error.message'), $invented->json('error.message'));
    }

    #[Test]
    public function an_address_the_customer_has_given_back_cannot_be_renamed(): void
    {
        [$customer, $user] = $this->accountWith();

        // Very likely somebody else's address by now. A PTR published onto it
        // would put this customer's name on a stranger's machine.
        $released = $this->assignmentFor($customer, ['released_at' => now()->subDay()]);

        $this->actingAs($user)
            ->putJson('/api/v1/ips/'.$released->id.'/rdns', ['hostname' => 'stale.example.com'])
            ->assertNotFound();

        $this->assertDatabaseCount('reverse_dns_records', 0);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badHostnames(): array
    {
        return [
            'empty' => [''],
            'not qualified' => ['localhost'],
            'an IP address' => ['203.0.113.40'],
            'a numeric suffix' => ['host.203'],
            'leading hyphen' => ['-mail.example.com'],
            'trailing hyphen' => ['mail-.example.com'],
            'an empty label' => ['mail..example.com'],
            'a space' => ['mail example.com'],
            'an underscore' => ['mail_server.example.com'],
            'a wildcard' => ['*.example.com'],
            'a path' => ['example.com/../etc'],
            // The two that matter most: a DNS API is spoken over HTTP and a
            // zone file is line-oriented, so a newline in a value is how one
            // field becomes two.
            'a newline' => ["mail.example.com\nx-injected: 1"],
            'a carriage return' => ["mail.example.com\r\nfoo.example.com"],
            'a label over 63 characters' => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.example.com'],
            'a name over 253 characters' => ['a.'.str_repeat('abcdefghij.', 25).'example.com'],
            'non-ascii' => ['méjl.example.com'],
        ];
    }

    #[Test]
    #[DataProvider('badHostnames')]
    public function a_string_that_is_not_a_hostname_is_refused_before_anything_sees_it(string $hostname): void
    {
        [$customer, $user] = $this->accountWith();
        $assignment = $this->assignmentFor($customer);

        $this->actingAs($user)
            ->putJson('/api/v1/ips/'.$assignment->id.'/rdns', ['hostname' => $hostname])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['hostname']]]]);

        $this->assertDatabaseCount('reverse_dns_records', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_hostname_is_required(): void
    {
        [$customer, $user] = $this->accountWith();
        $assignment = $this->assignmentFor($customer);

        $this->actingAs($user)
            ->putJson('/api/v1/ips/'.$assignment->id.'/rdns', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['hostname']]]]);
    }

    #[Test]
    public function an_address_and_a_hostname_in_the_body_cannot_redirect_the_record(): void
    {
        [$customer, $user] = $this->accountWith();
        [$theirs] = $this->accountWith();

        $mine = $this->assignmentFor($customer, address: '203.0.113.50');
        $hers = $this->assignmentFor($theirs, address: '203.0.113.51');

        $this->actingAs($user)
            ->putJson('/api/v1/ips/'.$mine->id.'/rdns', [
                'hostname' => 'mine.example.com',
                // Every one of these is a way of naming somebody else's row.
                // The action reads none of them; the address is the one in the
                // path, resolved through the acting customer's own relation.
                'assignment_id' => $hers->id,
                'ip_address_id' => $hers->ip_address_id,
                'customer_id' => $theirs->id,
            ])
            ->assertStatus(202);

        $this->assertDatabaseHas('reverse_dns_records', [
            'ip_address_id' => $mine->ip_address_id,
            'hostname' => 'mine.example.com',
        ]);

        $this->assertDatabaseMissing('reverse_dns_records', [
            'ip_address_id' => $hers->ip_address_id,
        ]);
    }

    #[Test]
    public function a_private_address_has_no_reverse_zone_to_publish_into(): void
    {
        [$customer, $user] = $this->accountWith();

        $assignment = $this->assignmentFor($customer, subnet: $this->privateSubnet(), address: '10.20.30.40');

        // 409, not 422: nothing about the request is malformed. RFC 1918 space
        // is delegated to nobody, so there is no zone to write into — and being
        // told that now beats a provider rejection the customer never sees.
        $this->actingAs($user)
            ->putJson('/api/v1/ips/'.$assignment->id.'/rdns', ['hostname' => 'internal.example.com'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ipam.reverse_dns_unavailable');

        $this->assertDatabaseCount('reverse_dns_records', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_member_who_may_only_view_cannot_rewrite_the_record(): void
    {
        [$customer, $member] = $this->accountWith(CustomerRole::Member);

        $assignment = $this->assignmentFor($customer);

        // A colleague added to watch the estate carries service.view and not
        // service.manage. A PTR decides whether this customer's mail is
        // accepted by anybody, which is not a viewer's decision.
        $this->actingAs($member)
            ->putJson('/api/v1/ips/'.$assignment->id.'/rdns', ['hostname' => 'member.example.com'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');

        $this->assertDatabaseCount('reverse_dns_records', 0);

        // The same member can still read the address.
        $this->actingAs($member)
            ->getJson('/api/v1/ips/'.$assignment->id)
            ->assertOk();
    }

    #[Test]
    public function the_refusal_for_a_viewer_does_not_depend_on_the_id_being_real(): void
    {
        [, $member] = $this->accountWith(CustomerRole::Member);

        // The permission check runs before any lookup, so a viewer gets the same
        // 403 for an invented id as for a real one — the answer depends on their
        // role and never on which ids exist.
        $this->actingAs($member)
            ->putJson('/api/v1/ips/'.Str::ulid().'/rdns', ['hostname' => 'member.example.com'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');
    }

    #[Test]
    public function a_credential_quoted_in_a_stored_provider_error_is_never_published(): void
    {
        [$customer, $user] = $this->accountWith();
        $assignment = $this->assignmentFor($customer);

        $record = ReverseDnsRecord::factory()->create([
            'ip_address_id' => $assignment->ip_address_id,
            'hostname' => 'mail.example.com',
        ]);

        // A zone client that fails quotes the request it sent, token and all.
        $record->recordFailure(sprintf(
            'PATCH /zones/rdns failed: 401 (Authorization: Bearer %s)',
            SecretFixtures::STRIPE_SECRET_KEY,
        ));

        $body = (string) $this->actingAs($user)
            ->getJson('/api/v1/ips/'.$assignment->id)
            ->assertOk()
            ->getContent();

        // Redacted in the column, and absent from the response either way.
        $this->assertStringNotContainsString(SecretFixtures::STRIPE_SECRET_KEY, $body);
        $this->assertStringNotContainsString('Authorization', $body);
        $this->assertStringNotContainsString('last_error', $body);
    }
}
