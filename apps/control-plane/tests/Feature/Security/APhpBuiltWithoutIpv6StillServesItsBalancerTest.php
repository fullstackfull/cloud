<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Lynomia\Http\Middleware\TrustProxies;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\TrustProxiesOnAPhpBuiltWithoutIpv6;
use Tests\TestCase;

/**
 * Deciding whether an IPv6 entry trusts every caller means asking `IpUtils`
 * about an IPv6 address, and a PHP built without IPv6 throws instead of
 * answering. The middleware runs on every request, so a throw there would be
 * a 500 on every request — including for a deployment that named only IPv4
 * balancers, if the IPv6 question were asked of an IPv4 list.
 *
 * So the middleware asks each family's question only of that family's
 * entries, and never of an empty list; and an IPv6 entry this build cannot
 * evaluate is refused rather than trusted blind or allowed to throw. The
 * IPv4 balancer beside it keeps working.
 *
 * What stays open, deliberately: on such a build a caller already behind a
 * trusted balancer can put an IPv6 address in `X-Forwarded-For` and reach the
 * same throw through the framework's own chain filter. That is recorded in the
 * middleware's docblock with the reason each per-request repair is worse.
 *
 * The build is simulated by {@see TrustProxiesOnAPhpBuiltWithoutIpv6}, bound
 * in place of the middleware for each test.
 */
final class APhpBuiltWithoutIpv6StillServesItsBalancerTest extends TestCase
{
    use RefreshDatabase;

    private const string BALANCER = '10.0.0.5';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(TrustProxies::class, TrustProxiesOnAPhpBuiltWithoutIpv6::class);

        Route::get('__test/client-address', static fn (Request $request): array => ['ip' => $request->ip()]);
    }

    /**
     * The positive control. Without it every test below could pass against a
     * double that simulates nothing.
     */
    #[Test]
    public function the_double_really_cannot_evaluate_an_ipv6_address_against_a_list(): void
    {
        $covers = new ReflectionMethod(TrustProxiesOnAPhpBuiltWithoutIpv6::class, 'covers');
        $double = new TrustProxiesOnAPhpBuiltWithoutIpv6;

        $this->assertFalse($covers->invoke($double, '::', []), 'An empty list never reaches the comparison, on any build.');
        $this->assertTrue($covers->invoke($double, '0.0.0.0', ['0.0.0.0/0']), 'IPv4 is unaffected by the build.');

        $this->expectException(RuntimeException::class);
        $covers->invoke($double, '::', ['10.0.0.5']);
    }

    /**
     * The double is what the kernel actually runs — otherwise these tests
     * measure the unaltered middleware and prove nothing about the build.
     */
    #[Test]
    public function the_kernel_runs_the_double(): void
    {
        $this->assertInstanceOf(TrustProxiesOnAPhpBuiltWithoutIpv6::class, $this->app->make(TrustProxies::class));
    }

    #[Test]
    public function with_nothing_configured_every_request_is_served(): void
    {
        config()->set('security.trusted_proxies', []);

        $this->withServerVariables(['REMOTE_ADDR' => self::BALANCER])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->getJson('/__test/client-address')
            ->assertOk()
            ->assertExactJson(['ip' => self::BALANCER]);
    }

    /**
     * The case the family split exists for: an IPv4-only deployment asks no
     * IPv6 question at all, so the build's missing support never matters.
     */
    #[Test]
    public function an_ipv4_balancer_is_trusted(): void
    {
        config()->set('security.trusted_proxies', [self::BALANCER, '10.0.1.0/24']);

        $this->withServerVariables(['REMOTE_ADDR' => self::BALANCER])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->getJson('/__test/client-address')
            ->assertOk()
            ->assertExactJson(['ip' => '203.0.113.7']);
    }

    #[Test]
    public function an_ipv6_entry_the_build_cannot_evaluate_is_refused_and_the_ipv4_balancer_beside_it_is_kept(): void
    {
        config()->set('security.trusted_proxies', [self::BALANCER, '2001:db8::5']);

        $this->withServerVariables(['REMOTE_ADDR' => self::BALANCER])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->getJson('/__test/client-address')
            ->assertOk()
            ->assertExactJson(['ip' => '203.0.113.7']);

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::5'])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->getJson('/__test/client-address')
            ->assertOk()
            ->assertExactJson(['ip' => '2001:db8::5']);
    }

    /**
     * An entry that could trust every IPv6 caller, on a build that cannot say
     * whether it does, is not trusted: the unknown answer is treated as the
     * dangerous one.
     */
    #[Test]
    public function an_ipv6_wildcard_the_build_cannot_evaluate_is_refused_rather_than_trusted(): void
    {
        config()->set('security.trusted_proxies', ['::/0']);

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:ffff::9'])
            ->withHeader('X-Forwarded-For', '192.0.2.77')
            ->getJson('/__test/client-address')
            ->assertOk()
            ->assertExactJson(['ip' => '2001:db8:ffff::9']);
    }

    /**
     * Through a real route that reads the client address — sign-in, whose
     * limiter is keyed on it — rather than only the probe.
     */
    #[Test]
    public function sign_in_answers_normally_on_such_a_build(): void
    {
        $this->seed(RolePermissionSeeder::class);

        config()->set('security.trusted_proxies', [self::BALANCER, '2001:db8::/64', '::/0']);

        $this->withServerVariables(['REMOTE_ADDR' => self::BALANCER])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->postJson(route('api.v1.login'), [
                'email' => 'nobody@example.com',
                'password' => 'Spring2026!',
            ])
            ->assertStatus(422);
    }
}
