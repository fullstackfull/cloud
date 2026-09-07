<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/ips/{assignment}.
 *
 * One address, and the several ways of asking for one that is not yours — each
 * of which must be answered identically, because the ids here are ULIDs and any
 * difference between "no such assignment" and "not your assignment" is an
 * enumeration oracle over the platform's entire address space.
 */
final class IpAddressShowEndpointTest extends IpamApiTestCase
{
    #[Test]
    public function it_shows_an_address_the_acting_customer_holds(): void
    {
        [$customer, $user] = $this->accountWith();

        $assignment = $this->assignmentFor($customer, address: '203.0.113.30');

        $this->actingAs($user)
            ->getJson('/api/v1/ips/'.$assignment->id)
            ->assertOk()
            ->assertJsonPath('data.id', $assignment->id)
            ->assertJsonPath('data.ip_address', '203.0.113.30')
            ->assertJsonPath('data.service_id', $assignment->service_id)
            ->assertJsonPath('data.network.prefix_length', 24);
    }

    #[Test]
    public function another_customers_address_is_not_found(): void
    {
        [, $user] = $this->accountWith();
        [$theirs] = $this->accountWith();

        $hers = $this->assignmentFor($theirs);

        // 404 and not 403. A 403 would confirm the row exists, which is the
        // whole of what an attacker walking ULIDs is trying to learn.
        $this->actingAs($user)
            ->getJson('/api/v1/ips/'.$hers->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');
    }

    #[Test]
    public function an_id_that_does_not_exist_is_answered_identically(): void
    {
        [, $user] = $this->accountWith();
        [$theirs] = $this->accountWith();

        $hers = $this->assignmentFor($theirs);

        $real = $this->actingAs($user)->getJson('/api/v1/ips/'.$hers->id);
        $invented = $this->actingAs($user)->getJson('/api/v1/ips/'.Str::ulid());

        // Byte for byte the same, so the difference between a real stranger's
        // id and an invented one cannot be read off the response.
        $this->assertSame($real->status(), $invented->status());
        $this->assertSame($real->json('error.code'), $invented->json('error.code'));
        $this->assertSame($real->json('error.message'), $invented->json('error.message'));
    }

    #[Test]
    public function an_address_the_customer_has_given_back_is_not_found(): void
    {
        [$customer, $user] = $this->accountWith();

        // The row still names this customer — it is kept forever, to answer an
        // abuse report — but the address itself may be somebody else's by now.
        $released = $this->assignmentFor($customer, ['released_at' => now()->subDay()]);

        $this->actingAs($user)
            ->getJson('/api/v1/ips/'.$released->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');
    }

    #[Test]
    public function nothing_internal_is_published(): void
    {
        [$customer, $user] = $this->accountWith();

        $assignment = $this->assignmentFor($customer, address: '203.0.113.31');
        $assignment->ipAddress->forceFill(['notes' => 'held for the noc migration'])->save();

        $response = $this->actingAs($user)->getJson('/api/v1/ips/'.$assignment->id)->assertOk();

        /** @var array<string, mixed> $row */
        $row = $response->json('data');

        foreach ([
            'ip_address_id',
            'subnet_id',
            'ip_pool_id',
            'customer_id',
            'assignable_type',
            'assignable_id',
            'released_at',
            'status',
            'notes',
            'last_error',
            'cidr',
        ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row, sprintf('%s must not be published.', $forbidden));
        }

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('held for the noc migration', $body);
        $this->assertStringNotContainsString($this->publicSubnet()->ip_pool_id, $body);
    }
}
