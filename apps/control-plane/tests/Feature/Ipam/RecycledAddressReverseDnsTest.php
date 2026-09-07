<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;

/**
 * An address that changed hands, and the one row that does not change with it.
 *
 * `ip_addresses` and `ip_assignments` are both handled correctly when an address
 * is recycled: the old assignment is stamped released and the new customer gets
 * a new row. `reverse_dns_records` is not. It is unique on `ip_address_id` — one
 * row per address, for the life of the address, across every customer that ever
 * holds it — and `IpAllocator::withdrawReverseDns()` only marks it `removing`;
 * nothing deletes it and nothing clears the hostname.
 *
 * So the row that the next holder's assignment eager-loads through
 * `ipAddress.reverseDnsRecord` is still the *previous* customer's PTR, carrying
 * the previous customer's hostname — their company name, their mail host, their
 * identity — published to a stranger by the address surface.
 */
final class RecycledAddressReverseDnsTest extends IpamApiTestCase
{
    #[Test]
    public function the_previous_holders_hostname_is_not_shown_to_the_next_customer(): void
    {
        [$first] = $this->accountWith();
        [$second, $secondUser] = $this->accountWith();

        // The first customer holds the address and names it.
        $firstAssignment = $this->assignmentFor($first, address: '203.0.113.70');

        ReverseDnsRecord::factory()->create([
            'ip_address_id' => $firstAssignment->ip_address_id,
            'hostname' => 'mail.first-customer.example.com',
            'status' => ReverseDnsStatus::Active,
        ]);

        // They cancel. The address goes to quarantine, the PTR is marked for
        // withdrawal — and the row, with their hostname on it, stays.
        $this->app->make(IpAllocator::class)
            ->releaseAssignment($firstAssignment, ReleaseReason::ServiceTerminated);

        // The quarantine elapses and the address is handed to somebody else.
        $secondAssignment = IpAssignment::factory()->create([
            'ip_address_id' => $firstAssignment->ip_address_id,
            'customer_id' => $second->getKey(),
            'service_id' => Service::factory()->create(['customer_id' => $second->getKey()])->getKey(),
            'is_primary' => true,
            'assigned_at' => now(),
        ]);

        foreach ([
            '/api/v1/ips',
            '/api/v1/ips/'.$secondAssignment->id,
        ] as $url) {
            $body = (string) $this->actingAs($secondUser)->getJson($url)->assertOk()->getContent();

            $this->assertStringNotContainsString(
                'mail.first-customer.example.com',
                $body,
                sprintf('%s published the previous holder of the address their PTR hostname.', $url),
            );
        }

        $this->actingAs($secondUser)
            ->getJson('/api/v1/ips/'.$secondAssignment->id)
            ->assertOk()
            ->assertJsonPath('data.reverse_dns', null);
    }

    #[Test]
    public function a_reverse_record_older_than_the_assignment_is_not_shown(): void
    {
        [$customer, $user] = $this->accountWith();

        $assignment = $this->assignmentFor($customer, address: '203.0.113.71');

        /*
         * A record that was last written before this customer was given the
         * address cannot be theirs, whatever its status says. Written straight
         * to the columns because that is exactly how it arises: a release path
         * that does not mark the row `removing`, an operator's default name, a
         * reconciler that put the status back.
         */
        $record = ReverseDnsRecord::factory()->create([
            'ip_address_id' => $assignment->ip_address_id,
            'hostname' => 'someone-else.example.com',
            'status' => ReverseDnsStatus::Active,
        ]);

        DB::table('reverse_dns_records')
            ->where('id', $record->getKey())
            ->update(['updated_at' => now()->subMonth(), 'created_at' => now()->subMonth()]);

        $this->actingAs($user)
            ->getJson('/api/v1/ips/'.$assignment->id)
            ->assertOk()
            ->assertJsonPath('data.reverse_dns', null);
    }

    #[Test]
    public function the_current_holder_still_sees_the_record_they_asked_for(): void
    {
        [$first] = $this->accountWith();
        [$second, $secondUser] = $this->accountWith();

        $firstAssignment = $this->assignmentFor($first, address: '203.0.113.72');

        ReverseDnsRecord::factory()->create([
            'ip_address_id' => $firstAssignment->ip_address_id,
            'hostname' => 'mail.first-customer.example.com',
            'status' => ReverseDnsStatus::Active,
        ]);

        $this->app->make(IpAllocator::class)
            ->releaseAssignment($firstAssignment, ReleaseReason::ServiceTerminated);

        $secondAssignment = IpAssignment::factory()->create([
            'ip_address_id' => $firstAssignment->ip_address_id,
            'customer_id' => $second->getKey(),
            'service_id' => Service::factory()->create(['customer_id' => $second->getKey()])->getKey(),
            'is_primary' => true,
            'assigned_at' => now(),
        ]);

        // The new holder names the address themselves: one row, now theirs, and
        // visible to them.
        $this->actingAs($secondUser)
            ->putJson('/api/v1/ips/'.$secondAssignment->id.'/rdns', ['hostname' => 'mail.second.example.com'])
            ->assertStatus(202)
            ->assertJsonPath('data.reverse_dns.hostname', 'mail.second.example.com');

        $this->actingAs($secondUser)
            ->getJson('/api/v1/ips/'.$secondAssignment->id)
            ->assertOk()
            ->assertJsonPath('data.reverse_dns.hostname', 'mail.second.example.com')
            ->assertJsonPath('data.reverse_dns.status', ReverseDnsStatus::Pending->value);
    }
}
