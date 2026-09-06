<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The auth limiters must bound both attack shapes, not just one.
 *
 * Keying only on `email|ip` bounds repeated guessing against ONE address and
 * nothing else: the address is chosen by the caller, so every new address is a
 * fresh bucket and one host may spray an unbounded number of them. A second,
 * deliberately roomier per-IP bucket is what caps that.
 */
final class AuthRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function one_host_cannot_spray_an_unbounded_number_of_addresses(): void
    {
        // A credential-stuffing pass: one password, a long list of addresses,
        // one host. Every address is a fresh per-address bucket, so only a
        // per-IP ceiling can stop it.
        $throttled = 0;
        $earlyRequestsThrottled = 0;

        for ($i = 0; $i < 200; $i++) {
            $status = $this->postJson(route('api.v1.login'), [
                'email' => "victim{$i}@example.com",
                'password' => 'Spring2026!',
            ])->getStatusCode();

            if ($status === 429) {
                $throttled++;

                if ($i < 20) {
                    $earlyRequestsThrottled++;
                }
            }
        }

        $this->assertGreaterThan(0, $throttled, 'One host sprayed 200 addresses without ever being throttled.');

        // The ceiling has to sit above what a shared egress address produces
        // legitimately, or it becomes a way to deny sign-in to everyone behind
        // an office NAT.
        $this->assertSame(0, $earlyRequestsThrottled);
    }

    #[Test]
    public function the_per_ip_ceiling_does_not_follow_the_attacker_to_another_host(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->postJson(route('api.v1.login'), [
                'email' => "victim{$i}@example.com",
                'password' => 'Spring2026!',
            ]);
        }

        // A different source address has its own budget: the ceiling bounds a
        // host, it does not become a way to deny sign-in to the whole internet.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson(route('api.v1.login'), [
                'email' => 'someone-else@example.com',
                'password' => 'Spring2026!',
            ])->assertStatus(422);
    }

    /**
     * POST /login/two-factor carries a challenge token and a code — never an
     * `email` — so a limiter keyed on the address degenerates to one shared
     * bucket per source IP, and anyone behind an office NAT could exhaust it
     * and stop everyone else there from completing sign-in.
     */
    #[Test]
    public function one_two_factor_attempt_does_not_consume_another_attempts_budget(): void
    {
        $attempts = (int) config('security.rate_limits.two_factor.attempts');

        for ($i = 0; $i < $attempts; $i++) {
            $this->postJson(route('api.v1.login.two_factor'), [
                'challenge_token' => 'challenge-belonging-to-someone-else',
                'code' => '000000',
            ])->assertStatus(422);
        }

        // That challenge's own budget is now spent...
        $this->postJson(route('api.v1.login.two_factor'), [
            'challenge_token' => 'challenge-belonging-to-someone-else',
            'code' => '000000',
        ])->assertStatus(429);

        // ...but a different sign-in from the same address still has its own.
        $this->postJson(route('api.v1.login.two_factor'), [
            'challenge_token' => 'a-different-challenge',
            'code' => '000000',
        ])->assertStatus(422);
    }
}
