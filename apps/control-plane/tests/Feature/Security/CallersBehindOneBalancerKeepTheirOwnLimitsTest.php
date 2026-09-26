<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Behind a load balancer every request arrives from the balancer, so the only
 * thing that tells two customers apart is the `X-Forwarded-For` the balancer
 * adds — and that header is believed only from an address the application
 * has been told to trust.
 *
 * When that list failed to load (see
 * {@see TheBalancerIsTrustedFromTheEnvironmentFileTest}), every limiter keyed
 * on `$request->ip()` — the per-address sign-in ceiling, registration, the
 * anonymous API bucket, webhooks — collapsed into one bucket shared by every
 * customer on the internet. One noisy client then denied sign-in to all of
 * them.
 *
 * The list is now configuration, read by `Lynomia\Http\Middleware\TrustProxies`
 * when each request arrives rather than captured once when the kernel is
 * built. These tests set it the way a deployment's configuration does and
 * watch what the platform does with it.
 */
final class CallersBehindOneBalancerKeepTheirOwnLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const string BALANCER = '10.0.0.5';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Route::get('__test/client-address', static fn (Request $request): array => ['ip' => $request->ip()]);
    }

    /**
     * The failure the audit named, end to end: one customer spends the
     * per-address sign-in ceiling, and a different customer behind the same
     * balancer must still be able to sign in.
     */
    #[Test]
    public function two_customers_behind_the_balancer_do_not_share_a_sign_in_ceiling(): void
    {
        config()->set('security.trusted_proxies', [self::BALANCER]);

        $throttled = false;

        for ($i = 0; $i < 100 && ! $throttled; $i++) {
            $throttled = $this->fromBehindTheBalancer('203.0.113.7')
                ->postJson(route('api.v1.login'), [
                    'email' => "victim{$i}@example.com",
                    'password' => 'Spring2026!',
                ])->getStatusCode() === 429;
        }

        // Precondition, not the claim: the first customer really did reach
        // the per-address ceiling, so the next assertion is about whose
        // budget that was.
        $this->assertTrue($throttled, 'The first customer was never throttled, so this test measures nothing.');

        $this->fromBehindTheBalancer('198.51.100.23')
            ->postJson(route('api.v1.login'), [
                'email' => 'someone-else@example.com',
                'password' => 'Spring2026!',
            ])
            ->assertStatus(422);
    }

    /**
     * Read when the request arrives, not when the application boots: the
     * same application answers differently once its configuration names the
     * balancer. A value captured at boot — the defect's shape — cannot.
     */
    #[Test]
    public function the_list_is_read_when_the_request_arrives(): void
    {
        config()->set('security.trusted_proxies', []);

        $this->fromBehindTheBalancer('203.0.113.7')
            ->getJson('/__test/client-address')
            ->assertOk()
            ->assertExactJson(['ip' => self::BALANCER]);

        config()->set('security.trusted_proxies', [self::BALANCER]);

        $this->fromBehindTheBalancer('203.0.113.7')
            ->getJson('/__test/client-address')
            ->assertOk()
            ->assertExactJson(['ip' => '203.0.113.7']);
    }

    /**
     * The customer is the nearest untrusted address, so a value the customer
     * wrote into the header themselves is not believed: only the balancer's
     * own entry is.
     */
    #[Test]
    public function a_customer_cannot_choose_their_own_address_through_the_balancer(): void
    {
        config()->set('security.trusted_proxies', [self::BALANCER]);

        $this->fromBehindTheBalancer('192.0.2.200, 203.0.113.7')
            ->getJson('/__test/client-address')
            ->assertOk()
            ->assertExactJson(['ip' => '203.0.113.7']);
    }

    /**
     * And a caller that is not the balancer gains nothing by sending the
     * header: it is keyed on the address it connected from.
     */
    #[Test]
    public function a_caller_that_is_not_the_balancer_is_keyed_on_its_own_address(): void
    {
        config()->set('security.trusted_proxies', [self::BALANCER]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->getJson('/__test/client-address')
            ->assertOk()
            ->assertExactJson(['ip' => '198.51.100.9']);
    }

    private function fromBehindTheBalancer(string $forwardedFor): self
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::BALANCER])
            ->withHeader('X-Forwarded-For', $forwardedFor);
    }
}
