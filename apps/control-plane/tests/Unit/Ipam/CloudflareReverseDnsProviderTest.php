<?php

declare(strict_types=1);

namespace Tests\Unit\Ipam;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Ipam\Domain\Exceptions\ReverseDnsProviderException;
use Lynomia\Modules\Ipam\Domain\Exceptions\ReverseDnsUnavailableException;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Hostname;
use Lynomia\Modules\Ipam\Domain\ValueObjects\IpAddressValue;
use Lynomia\Modules\Ipam\Infrastructure\Providers\CloudflareReverseDnsProvider;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reverse DNS through Cloudflare — and, more importantly, the refusal.
 *
 * Reverse zones are delegated by whoever assigned the address block, not by
 * whoever holds the domain. An account can serve every forward zone this
 * platform owns and have no authority over one PTR, so the interesting case is
 * not the write: it is what happens when there is nowhere to write.
 *
 * The failure this suite exists to make impossible is an adapter that reports a
 * PTR as published when no zone was found. That produces a platform whose
 * dashboard says a customer's reverse DNS is live while every receiver that
 * checks one rejects their mail — invisible from the platform, expensive for
 * the customer, which is the worst pair of properties a defect can have.
 *
 * Nothing here has spoken to Cloudflare. Status: TESTED, not
 * REAL_INFRA_VERIFIED.
 */
final class CloudflareReverseDnsProviderTest extends TestCase
{
    private const string TOKEN = 'cf-test-token-abcdef0123456789';

    private const string BASE = 'https://api.cloudflare.test/client/v4';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cloudflare', [
            'api_token' => self::TOKEN,
            'base_url' => self::BASE,
            'timeout' => 5,
            'verify_tls' => true,
        ]);
    }

    private function provider(): CloudflareReverseDnsProvider
    {
        return new CloudflareReverseDnsProvider($this->app->make(SecretRedactor::class));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function ok(array $result): array
    {
        return ['success' => true, 'errors' => [], 'messages' => [], 'result' => $result];
    }

    /**
     * Answers zone lookups for exactly the reverse zones an account holds, and
     * record lookups with whatever that zone currently contains.
     *
     * @param  array<string, string>  $reverseZones  zone name => zone id
     * @param  list<array<string, mixed>>  $records
     */
    private function cloudflareHolding(array $reverseZones, array $records = []): void
    {
        Http::fake(function (Request $request) use ($reverseZones, $records) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_contains((string) parse_url($request->url(), PHP_URL_PATH), '/dns_records')) {
                return Http::response(self::ok($request->method() === 'GET' ? $records : ['id' => 'rec-new']));
            }

            $name = (string) ($query['name'] ?? '');

            return Http::response(self::ok(
                isset($reverseZones[$name]) ? [['id' => $reverseZones[$name], 'name' => $name]] : [],
            ));
        });
    }

    #[Test]
    public function it_derives_the_ptr_name_for_both_address_families(): void
    {
        $this->assertSame(
            '10.2.0.192.in-addr.arpa',
            CloudflareReverseDnsProvider::ptrNameFor(IpAddressValue::fromString('192.0.2.10')),
        );

        // The nibble form, most significant last, as RFC 3596 defines it.
        $this->assertSame(
            '8.b.d.0.1.0.0.2.ip6.arpa',
            substr(CloudflareReverseDnsProvider::ptrNameFor(IpAddressValue::fromString('2001:db8::1')), -24),
        );
    }

    #[Test]
    public function it_publishes_into_the_reverse_zone_the_account_holds(): void
    {
        $this->cloudflareHolding(['2.0.192.in-addr.arpa' => 'rev-zone']);

        $this->provider()->publish(
            IpAddressValue::fromString('192.0.2.10'),
            Hostname::fromString('vps-1.lynomia.test'),
        );

        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/zones/rev-zone/dns_records')
                && ($request['type'] ?? null) === 'PTR'
                && ($request['name'] ?? null) === '10.2.0.192.in-addr.arpa'
                && ($request['content'] ?? null) === 'vps-1.lynomia.test';
        });
    }

    #[Test]
    public function it_prefers_the_most_specific_delegation(): void
    {
        // A /24 delegated to this account, and the /16 also in it. The record
        // belongs in the /24: that is where the delegation points, and writing
        // it to the /16 would put it in a zone that is not authoritative.
        $this->cloudflareHolding([
            '2.0.192.in-addr.arpa' => 'rev-24',
            '0.192.in-addr.arpa' => 'rev-16',
        ]);

        $this->provider()->publish(
            IpAddressValue::fromString('192.0.2.10'),
            Hostname::fromString('vps-1.lynomia.test'),
        );

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/zones/rev-24/'));
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '/zones/rev-16/dns_records'));
    }

    /*
     * -----------------------------------------------------------------------
     * The case this class exists for
     * -----------------------------------------------------------------------
     */

    #[Test]
    public function an_account_with_no_reverse_zone_is_told_so_and_nothing_is_published(): void
    {
        // The account holds the forward domain and no reverse delegation at
        // all — the ordinary situation for a provider whose address space was
        // assigned by an upstream that kept the arpa zones.
        $this->cloudflareHolding(['lynomia.test' => 'fwd-zone']);

        try {
            $this->provider()->publish(
                IpAddressValue::fromString('192.0.2.10'),
                Hostname::fromString('vps-1.lynomia.test'),
            );
            $this->fail('A PTR was reported as published with no zone to publish it into.');
        } catch (ReverseDnsUnavailableException $e) {
            // Its own code, because the remedy is an operator arranging the
            // delegation — not the customer changing anything.
            $this->assertSame('ipam.reverse_dns_provider_unavailable', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());

            $context = $e->context();
            $this->assertSame('192.0.2.10', $context['address'] ?? null);
            $this->assertSame('10.2.0.192.in-addr.arpa', $context['ptr_name'] ?? null);
        }

        // The whole point: no write was attempted, and no success was reported.
        Http::assertNotSent(static fn (Request $r): bool => in_array($r->method(), ['POST', 'PUT'], true));
    }

    #[Test]
    public function the_capability_answer_does_not_leak_the_credential(): void
    {
        $this->cloudflareHolding([]);

        try {
            $this->provider()->publish(
                IpAddressValue::fromString('192.0.2.10'),
                Hostname::fromString('vps-1.lynomia.test'),
            );
            $this->fail('A PTR was reported as published with no zone to publish it into.');
        } catch (ReverseDnsUnavailableException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, json_encode($e->context(), JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function republishing_the_same_hostname_writes_nothing(): void
    {
        $this->cloudflareHolding(
            ['2.0.192.in-addr.arpa' => 'rev-zone'],
            [['id' => 'rec-1', 'type' => 'PTR', 'name' => '10.2.0.192.in-addr.arpa', 'content' => 'vps-1.lynomia.test.']],
        );

        $this->provider()->publish(
            IpAddressValue::fromString('192.0.2.10'),
            Hostname::fromString('vps-1.lynomia.test'),
        );

        // Trailing dot and all: the zone already says this, and rewriting it
        // would bump the serial and re-propagate an unchanged record.
        Http::assertNotSent(static fn (Request $r): bool => in_array($r->method(), ['POST', 'PUT'], true));
    }

    #[Test]
    public function changing_the_hostname_replaces_the_existing_record(): void
    {
        $this->cloudflareHolding(
            ['2.0.192.in-addr.arpa' => 'rev-zone'],
            [['id' => 'rec-1', 'type' => 'PTR', 'name' => '10.2.0.192.in-addr.arpa', 'content' => 'old.lynomia.test']],
        );

        $this->provider()->publish(
            IpAddressValue::fromString('192.0.2.10'),
            Hostname::fromString('new.lynomia.test'),
        );

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'PUT' && str_ends_with($r->url(), '/rec-1'));
        Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'POST');
    }

    #[Test]
    public function a_call_that_never_came_back_stays_indeterminate_across_the_translation(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            $this->provider()->publish(
                IpAddressValue::fromString('192.0.2.10'),
                Hostname::fromString('vps-1.lynomia.test'),
            );
            $this->fail('A timeout was not reported.');
        } catch (ReverseDnsProviderException $e) {
            // The flag is what IPAM branches on to decide between telling the
            // customer it failed and leaving it pending for an operator.
            // Losing it in translation would turn every timeout into a
            // reported failure, and invite a retry of a write that may have
            // landed.
            $this->assertTrue($e->isIndeterminate());
        }
    }

    #[Test]
    public function a_refusal_stays_determinate_across_the_translation(): void
    {
        // The record pattern is listed first on purpose: Http::fake matches in
        // order and `/zones*` would otherwise swallow `/zones/rev-zone/dns_records`
        // and answer a record lookup with a zone listing.
        Http::fake([
            self::BASE.'/zones/rev-zone/dns_records*' => Http::response([
                'success' => false,
                'errors' => [['code' => 9005, 'message' => 'Content for PTR record is invalid.']],
            ], 400),
            self::BASE.'/zones*' => Http::response(self::ok([['id' => 'rev-zone', 'name' => '2.0.192.in-addr.arpa']])),
        ]);

        try {
            $this->provider()->publish(
                IpAddressValue::fromString('192.0.2.10'),
                Hostname::fromString('vps-1.lynomia.test'),
            );
            $this->fail('A refusal was not reported.');
        } catch (ReverseDnsProviderException $e) {
            $this->assertFalse($e->isIndeterminate());
            $this->assertStringContainsString('9005', $e->getMessage());
        }
    }

    #[Test]
    public function no_token_configured_is_a_determinate_failure_rather_than_an_unknown(): void
    {
        config()->set('services.cloudflare.api_token', '');
        Http::fake();

        try {
            $this->provider()->publish(
                IpAddressValue::fromString('192.0.2.10'),
                Hostname::fromString('vps-1.lynomia.test'),
            );
            $this->fail('A missing token was not reported.');
        } catch (ReverseDnsProviderException $e) {
            // Nothing was sent, so the outcome is known. Marking it
            // indeterminate would quarantine a record over a config typo.
            $this->assertFalse($e->isIndeterminate());
            $this->assertStringContainsString('services.cloudflare.api_token', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
