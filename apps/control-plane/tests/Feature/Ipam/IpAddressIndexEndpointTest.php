<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Http\Requests\ListIpAssignmentsRequest;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/ips.
 *
 * The addresses the acting customer holds right now — not the pool they came
 * out of, not the rest of the subnet, and not the ones they used to hold.
 */
final class IpAddressIndexEndpointTest extends IpamApiTestCase
{
    #[Test]
    public function it_lists_the_acting_customers_addresses(): void
    {
        [$customer, $user] = $this->accountWith();

        $assignment = $this->assignmentFor($customer, address: '203.0.113.10');

        $this->actingAs($user)
            ->getJson('/api/v1/ips')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignment->id)
            ->assertJsonPath('data.0.ip_address', '203.0.113.10')
            ->assertJsonPath('data.0.ip_version', 4)
            ->assertJsonPath('data.0.is_primary', true)
            ->assertJsonPath('data.0.network.prefix_length', 24)
            ->assertJsonPath('data.0.network.gateway', '203.0.113.1')
            ->assertJsonPath('data.0.reverse_dns', null)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function a_reverse_record_is_published_with_its_status_and_never_its_provider_error(): void
    {
        [$customer, $user] = $this->accountWith();

        $assignment = $this->assignmentFor($customer, address: '203.0.113.11');

        ReverseDnsRecord::factory()->create([
            'ip_address_id' => $assignment->ip_address_id,
            'hostname' => 'mail.example.com',
            'status' => ReverseDnsStatus::Failed,
            'last_error' => 'PATCH /zones/rdns 401 for zone 7fd2',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/ips')->assertOk();

        $response->assertJsonPath('data.0.reverse_dns.hostname', 'mail.example.com')
            ->assertJsonPath('data.0.reverse_dns.status', 'failed')
            ->assertJsonPath('data.0.reverse_dns.is_settled', true);

        // The status is a complete answer to "did my name take?". What the
        // provider said about it is support vocabulary, and is not published.
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('last_error', $body);
        $this->assertStringNotContainsString('7fd2', $body);
    }

    #[Test]
    public function another_customers_address_is_not_in_the_list(): void
    {
        [$mine, $user] = $this->accountWith();
        [$theirs] = $this->accountWith();

        $this->assignmentFor($mine);
        $hers = $this->assignmentFor($theirs);

        $response = $this->actingAs($user)->getJson('/api/v1/ips')->assertOk();

        $this->assertNotContains($hers->id, array_column((array) $response->json('data'), 'id'));
        $response->assertJsonCount(1, 'data');
    }

    #[Test]
    public function an_address_that_has_been_released_is_gone_from_the_list(): void
    {
        [$customer, $user] = $this->accountWith();

        /*
         * Assignment rows are never deleted — they are how "who held this
         * address on the 4th of March" is answered — and the released row still
         * carries this customer's id. A scope of "customer_id = me" alone would
         * keep showing them an address that has been quarantined and handed to
         * somebody else, and would let them set its reverse DNS.
         */
        $released = $this->assignmentFor($customer, ['released_at' => now()->subDay()]);

        $this->assertSame($customer->id, $released->refresh()->customer_id);

        $this->actingAs($user)
            ->getJson('/api/v1/ips')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_primary_address_comes_first(): void
    {
        [$customer, $user] = $this->accountWith();

        $secondary = $this->assignmentFor($customer, ['is_primary' => false, 'assigned_at' => now()]);
        $primary = $this->assignmentFor($customer, ['is_primary' => true, 'assigned_at' => now()->subWeek()]);

        $this->actingAs($user)
            ->getJson('/api/v1/ips')
            ->assertOk()
            ->assertJsonPath('data.0.id', $primary->id)
            ->assertJsonPath('data.1.id', $secondary->id);
    }

    #[Test]
    public function the_page_size_is_bounded(): void
    {
        [$customer, $user] = $this->accountWith();

        foreach (range(1, 5) as $ignored) {
            $this->assignmentFor($customer);
        }

        // Asking for a hundred thousand rows is answered with the ceiling
        // rather than refused — and the query is never handed the number.
        $this->actingAs($user)
            ->getJson('/api/v1/ips?per_page=100000')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', ListIpAssignmentsRequest::MAX_PER_PAGE)
            ->assertJsonPath('meta.max_per_page', ListIpAssignmentsRequest::MAX_PER_PAGE);

        // And a sane page size is honoured exactly.
        $this->actingAs($user)
            ->getJson('/api/v1/ips?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    #[Test]
    public function a_nonsense_page_size_is_treated_as_unspecified(): void
    {
        [$customer, $user] = $this->accountWith();
        $this->assignmentFor($customer);

        $this->actingAs($user)
            ->getJson('/api/v1/ips?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    #[Test]
    public function nothing_about_the_platforms_address_space_is_published(): void
    {
        [$customer, $user] = $this->accountWith();

        $assignment = $this->assignmentFor($customer, address: '203.0.113.20');
        $assignment->ipAddress->forceFill(['notes' => 'reclaimed from an abuse suspension'])->save();

        $response = $this->actingAs($user)->getJson('/api/v1/ips')->assertOk();

        /** @var array<string, mixed> $row */
        $row = $response->json('data.0');

        // Named one by one rather than asserted as a shape: a general check
        // passes just as happily when a new column is added to the resource.
        foreach ([
            'ip_address_id',   // the address's internal identity
            'subnet_id',       // the key to every other address in the block
            'ip_pool_id',
            'network_id',
            'datacenter_id',
            'cidr',
            'customer_id',     // the caller already knows whose account this is
            'assignable_type', // internal class names
            'assignable_id',
            'released_at',
            'status',          // the allocator's lifecycle vocabulary
            'quarantined_until',
            'quarantine_reason',
            'notes',           // free text written by engineers
            'last_error',
        ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row, sprintf('%s must not be published.', $forbidden));
        }

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('reclaimed from an abuse suspension', $body);
        $this->assertStringNotContainsString($assignment->ip_address_id, $body);
        $this->assertStringNotContainsString($this->publicSubnet()->id, $body);
        $this->assertStringNotContainsString($customer->id, $body);
    }
}
