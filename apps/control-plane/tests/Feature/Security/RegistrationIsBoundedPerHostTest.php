<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /api/v1/register is an unauthenticated, irreversible write: a users row
 * and a customers row, and — once verification mail is wired up — one outbound
 * message from the platform's SMTP identity to any address the caller names.
 *
 * Its only limit used to be `throttle:register` keyed on `email|ip`, so the
 * documented "5 attempts per 10 minutes" bounded how often one host could
 * re-submit ONE address. Varying the address made every request a fresh
 * bucket: 40 accounts from one host, zero 429s. The per-IP ceiling in
 * RateLimitServiceProvider is what bounds the table growth and the mail
 * volume; this asserts it applies to registration and not just to login.
 */
final class RegistrationIsBoundedPerHostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function one_host_cannot_register_an_unbounded_number_of_addresses(): void
    {
        $attempts = 60;
        $throttled = 0;
        $earlyThrottled = 0;

        for ($i = 0; $i < $attempts; $i++) {
            $status = $this->postJson('/api/v1/register', [
                'name' => "Flood {$i}",
                'email' => "flood{$i}@example.com",
                'password' => 'correct-horse-battery-9',
                'password_confirmation' => 'correct-horse-battery-9',
                'country' => 'KW',
                'accepts_terms' => true,
            ])->status();

            if ($status === 429) {
                $throttled++;

                if ($i < 5) {
                    $earlyThrottled++;
                }
            }
        }

        $this->assertGreaterThan(0, $throttled, 'One host registered every address it asked for.');
        $this->assertLessThan($attempts, User::query()->count());
        $this->assertLessThan($attempts, Customer::query()->count());

        // The ceiling has to sit above what a shared egress address produces
        // legitimately, or it becomes a way to stop an office from signing up.
        $this->assertSame(0, $earlyThrottled);
    }

    #[Test]
    public function the_ceiling_does_not_follow_the_attacker_to_another_host(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/register', [
                'name' => "Flood {$i}",
                'email' => "flood{$i}@example.com",
                'password' => 'correct-horse-battery-9',
                'password_confirmation' => 'correct-horse-battery-9',
                'country' => 'KW',
                'accepts_terms' => true,
            ]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/v1/register', [
                'name' => 'Genuine Customer',
                'email' => 'genuine@example.com',
                'password' => 'correct-horse-battery-9',
                'password_confirmation' => 'correct-horse-battery-9',
                'country' => 'KW',
                'accepts_terms' => true,
            ])->assertStatus(202);
    }
}
