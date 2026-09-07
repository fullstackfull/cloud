<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use PHPUnit\Framework\Attributes\Test;

final class ReverseDnsThrottleTest extends IpamApiTestCase
{
    #[Test]
    public function the_narrower_limit_is_per_caller_and_not_shared_between_customers(): void
    {
        [$noisy, $noisyUser] = $this->accountWith();
        [$quiet, $quietUser] = $this->accountWith();

        $noisyAssignment = $this->assignmentFor($noisy, address: '203.0.113.80');
        $quietAssignment = $this->assignmentFor($quiet, address: '203.0.113.81');

        $statuses = [];

        foreach (range(1, 12) as $i) {
            $statuses[] = $this->actingAs($noisyUser)
                ->putJson('/api/v1/ips/'.$noisyAssignment->id.'/rdns', ['hostname' => 'h'.$i.'.example.com'])
                ->status();
        }

        // The stated protection: the shared zone-API budget is bounded per
        // caller, so a client in a retry loop is cut off.
        $this->assertContains(429, $statuses, 'The narrower rDNS limit never engaged: '.implode(',', $statuses));

        // And it is the caller who is cut off, not the estate. A limiter keyed
        // by anything a second customer shares would let one client in a loop
        // stop everybody else naming their addresses.
        $this->actingAs($quietUser)
            ->putJson('/api/v1/ips/'.$quietAssignment->id.'/rdns', ['hostname' => 'quiet.example.com'])
            ->assertStatus(202);
    }
}
