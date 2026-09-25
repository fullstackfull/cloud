<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Trusting a caller means believing the address it says the customer has. A
 * list that trusts every caller therefore lets every caller choose its own
 * address, and with it a fresh bucket in every IP-keyed limiter on every
 * request — the opposite failure from the one F-31 was convened on, and the
 * worse one.
 *
 * `Lynomia\Http\Middleware\TrustProxies` refuses such entries rather than
 * passing them on. Each case below is sent from an address that is NOT a
 * balancer, carrying an `X-Forwarded-For` of the caller's choosing, and must
 * be keyed on the address it connected from.
 *
 * Refusal is silent, on purpose and for now: a request-path warning would fire
 * once per request, and the ledger ruled that the right instrument is a
 * deployment-time check against configuration. A refused list therefore
 * collapses the limiters back into the balancer's bucket — which is loud to
 * the operator watching sign-ins fail, and never lets a client choose its key.
 */
final class NoConfigurationTrustsEveryCallerTest extends TestCase
{
    private const string BALANCER = '10.0.0.5';

    private const string CHOSEN_BY_THE_CALLER = '192.0.2.77';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('__test/client-address', static fn (Request $request): array => ['ip' => $request->ip()]);
    }

    /**
     * @return array<string, array{0: list<string>|string, 1: string}>
     */
    public static function listsThatTrustEveryCaller(): array
    {
        return [
            'the wildcard' => [['*'], '198.51.100.9'],
            'the double wildcard' => [['**'], '198.51.100.9'],
            'the wildcard, as one string' => ['*', '198.51.100.9'],
            'whoever connects' => [['REMOTE_ADDR'], '198.51.100.9'],
            'every IPv4 address' => [['0.0.0.0/0'], '198.51.100.9'],
            'every IPv4 address, written from a host' => [['10.0.0.5/0'], '198.51.100.9'],
            'every IPv4 address, with a zero-padded prefix' => [['0.0.0.0/00'], '198.51.100.9'],
            'every IPv4 address, in two halves' => [['0.0.0.0/1', '128.0.0.0/1'], '198.51.100.9'],
            'every IPv4 address, in four quarters' => [['0.0.0.0/2', '64.0.0.0/2', '128.0.0.0/2', '192.0.0.0/2'], '198.51.100.9'],
            'every IPv6 address' => [['::/0'], '2001:db8:ffff::9'],
            'every IPv6 address, written from a host' => [['2001:db8::5/0'], '2001:db8:ffff::9'],
            'every IPv6 address, in two halves' => [['::/1', '8000::/1'], '2001:db8:ffff::9'],
            // The upper half on its own is not every caller, and the lower
            // half is also refused as covering every dual-stack IPv4 caller.
            // Only the whole-IPv6 range sees that the two halves together are
            // everything, so the caller is asked from the half it alone refuses.
            'every IPv6 address, in two halves, asked from the upper half' => [['::/1', '8000::/1'], 'fd00:ffff::9'],
            'every IPv4 caller on a dual-stack socket' => [['::ffff:0:0/96'], '::ffff:198.51.100.9'],
            'every IPv4 caller on a dual-stack socket, by a wider prefix' => [['::/64'], '::ffff:198.51.100.9'],
        ];
    }

    /**
     * @param  list<string>|string  $configured
     */
    #[Test]
    #[DataProvider('listsThatTrustEveryCaller')]
    public function a_list_that_would_trust_every_caller_trusts_none_of_them(array|string $configured, string $caller): void
    {
        config()->set('security.trusted_proxies', $configured);

        $this->withServerVariables(['REMOTE_ADDR' => $caller])
            ->withHeader('X-Forwarded-For', self::CHOSEN_BY_THE_CALLER)
            ->getJson('/__test/client-address')
            ->assertOk()
            ->assertExactJson(['ip' => $caller]);
    }

    /**
     * Refusing the dangerous entry must not take the real balancer down with
     * it, or an operator who added a wildcard "to be safe" loses the balancer
     * they had named correctly — and the refusal becomes the outage.
     */
    #[Test]
    public function the_balancer_named_beside_a_refused_entry_is_still_trusted(): void
    {
        config()->set('security.trusted_proxies', [self::BALANCER, '0.0.0.0/0', '::/0', '*']);

        $this->withServerVariables(['REMOTE_ADDR' => self::BALANCER])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->getJson('/__test/client-address')
            ->assertExactJson(['ip' => '203.0.113.7']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->withHeader('X-Forwarded-For', self::CHOSEN_BY_THE_CALLER)
            ->getJson('/__test/client-address')
            ->assertExactJson(['ip' => '198.51.100.9']);
    }

    /**
     * An entry that is not an address or a range is refused: a hostname, a
     * typo, a prefix too long for its family, and the framework's keywords
     * — `PRIVATE_SUBNETS` among them, which would trust every client inside
     * the platform's own networks to name its own address.
     */
    #[Test]
    public function an_entry_that_is_not_an_address_is_refused_and_the_rest_are_kept(): void
    {
        config()->set('security.trusted_proxies', [
            self::BALANCER, 'lb.internal', '10.0.0.300', '10.0.0.0/33', '10.0.0.0/', '2001:db8::/129', 'PRIVATE_SUBNETS', 'private_ranges',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => self::BALANCER])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->getJson('/__test/client-address')
            ->assertExactJson(['ip' => '203.0.113.7']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->withHeader('X-Forwarded-For', self::CHOSEN_BY_THE_CALLER)
            ->getJson('/__test/client-address')
            ->assertExactJson(['ip' => '10.1.2.3']);
    }

    /**
     * Illuminate's middleware trusts EVERY caller when it has no list and the
     * request's Host ends in `.on-forge.com` or `.on-vapor.com` — a guess at
     * which platform's balancer is in front. The Host header is the caller's
     * to write, and nothing in front of this application pins it, so on the
     * unrepaired tree any caller could send one and choose its own address.
     *
     * `config/trustedproxy.php` pins the framework's fallback list to empty,
     * which is never null, so that branch is unreachable.
     *
     * @return array<string, array{0: list<string>}>
     */
    public static function listsThatTrustNobody(): array
    {
        return [
            'nothing configured' => [[]],
            'only refused entries configured' => [['*', '0.0.0.0/0', 'REMOTE_ADDR']],
        ];
    }

    /**
     * @param  list<string>  $configured
     */
    #[Test]
    #[DataProvider('listsThatTrustNobody')]
    public function a_host_header_naming_a_hosting_platform_earns_no_trust(array $configured): void
    {
        config()->set('security.trusted_proxies', $configured);

        foreach (['anything.on-forge.com', 'anything.on-vapor.com'] as $host) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
                ->withHeader('X-Forwarded-For', self::CHOSEN_BY_THE_CALLER)
                ->getJson("http://{$host}/__test/client-address")
                ->assertOk()
                ->assertExactJson(['ip' => '198.51.100.9']);
        }
    }

    /**
     * `TrustProxies::at()` is process-wide static state, and it is what the
     * old `bootstrap/app.php` wrote. The list is configuration now, and a
     * value left in that static — by a package, or by a line somebody adds
     * back to the bootstrap — is not consulted.
     */
    #[Test]
    public function a_list_set_on_the_framework_at_boot_is_not_consulted(): void
    {
        config()->set('security.trusted_proxies', []);

        FrameworkTrustProxies::at('*');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->withHeader('X-Forwarded-For', self::CHOSEN_BY_THE_CALLER)
            ->getJson('/__test/client-address')
            ->assertExactJson(['ip' => '198.51.100.9']);
    }

    /**
     * The other half of the same static state: which headers are believed.
     * A header set narrowed or widened at boot is not consulted either, so
     * the balancer's `X-Forwarded-For` keeps meaning what the middleware
     * says it means.
     */
    #[Test]
    public function a_header_set_chosen_on_the_framework_at_boot_is_not_consulted(): void
    {
        config()->set('security.trusted_proxies', [self::BALANCER]);

        FrameworkTrustProxies::withHeaders(Request::HEADER_FORWARDED);

        $this->withServerVariables(['REMOTE_ADDR' => self::BALANCER])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->getJson('/__test/client-address')
            ->assertExactJson(['ip' => '203.0.113.7']);
    }
}
