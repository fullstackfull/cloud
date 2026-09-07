<?php

declare(strict_types=1);

namespace Tests\Unit\Dns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsNotConfiguredException;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsProviderException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsZone;
use Lynomia\Modules\Dns\Infrastructure\Providers\CloudflareDnsProvider;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The forward-DNS adapter, against recorded Cloudflare responses.
 *
 * This has never spoken to Cloudflare. Every response below is one this test
 * wrote, so what is proved here is that the adapter sends what the API
 * documents and reads what the API returns — not that Cloudflare agrees. That
 * distinction is why the status of this component is TESTED and not
 * REAL_INFRA_VERIFIED, and no amount of green here changes it.
 *
 * What is worth more than the happy path, and is asserted against the recorded
 * requests rather than assumed:
 *
 *  - the credential travels in the Authorization header and appears nowhere
 *    else — not in a URL, not in an exception message, not in a refusal the
 *    provider quoted back;
 *  - a publish that changes nothing sends no write. A zone serial bumped for
 *    an unchanged record re-propagates it to every resolver on the internet;
 *  - a timeout is indeterminate and is never resolved by trying again.
 */
final class CloudflareDnsProviderTest extends TestCase
{
    private const string TOKEN = 'cf-test-token-abcdef0123456789';

    private const string BASE = 'https://api.cloudflare.test/client/v4';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cloudflare', [
            'api_token' => self::TOKEN,
            'account_id' => null,
            'base_url' => self::BASE,
            'timeout' => 5,
            'verify_tls' => true,
        ]);
    }

    private function provider(): CloudflareDnsProvider
    {
        return new CloudflareDnsProvider($this->app->make(SecretRedactor::class));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function ok(array $result): array
    {
        return ['success' => true, 'errors' => [], 'messages' => [], 'result' => $result];
    }

    private static function zone(string $id = 'zone-1', string $name = 'lynomia.test'): DnsZone
    {
        return DnsZone::of($id, $name);
    }

    #[Test]
    public function it_looks_a_zone_up_by_exact_name(): void
    {
        Http::fake([
            self::BASE.'/zones*' => Http::response(self::ok([['id' => 'zone-9', 'name' => 'lynomia.test']])),
        ]);

        $zone = $this->provider()->findZone('LYNOMIA.test.');

        $this->assertNotNull($zone);
        $this->assertSame('zone-9', $zone->id());
        $this->assertSame('lynomia.test', $zone->name());

        Http::assertSent(static function (Request $request): bool {
            return str_contains($request->url(), 'name=lynomia.test')
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN);
        });
    }

    #[Test]
    public function the_credential_travels_in_a_header_and_never_in_a_url(): void
    {
        Http::fake([self::BASE.'/zones*' => Http::response(self::ok([]))]);

        $this->provider()->findZone('lynomia.test');

        Http::assertSent(static fn (Request $request): bool => ! str_contains($request->url(), self::TOKEN));
    }

    #[Test]
    public function it_finds_the_most_specific_zone_that_could_hold_a_name(): void
    {
        // The account holds the delegated sub-zone as well as the apex. A
        // record for a.b.lynomia.test belongs in b.lynomia.test, and writing
        // it into the apex instead would put it in a zone that is not
        // authoritative for the name.
        // Answered per requested name rather than by one catch-all stub: an
        // account holds the zones it holds and returns an empty result for
        // every other name, and a stub that answers everything cannot show
        // that the walk stops at the most specific match.
        $held = ['b.lynomia.test' => 'zone-sub', 'lynomia.test' => 'zone-apex'];

        Http::fake(function (Request $request) use ($held) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $name = (string) ($query['name'] ?? '');

            return Http::response(self::ok(
                isset($held[$name]) ? [['id' => $held[$name], 'name' => $name]] : [],
            ));
        });

        $zone = $this->provider()->zoneFor('a.b.lynomia.test');

        $this->assertNotNull($zone);
        $this->assertSame('zone-sub', $zone->id());
    }

    #[Test]
    public function it_returns_no_zone_when_the_account_holds_none_that_covers_the_name(): void
    {
        Http::fake([self::BASE.'/zones*' => Http::response(self::ok([]))]);

        $this->assertNull($this->provider()->zoneFor('www.somebody-elses-domain.test'));
    }

    #[Test]
    public function it_refuses_to_create_a_zone_without_an_account_to_create_it_in(): void
    {
        $provider = $this->provider();

        $this->assertFalse($provider->canCreateZones());

        try {
            $provider->createZone('new.test');
            $this->fail('A zone was created with no account configured.');
        } catch (DnsNotConfiguredException $e) {
            // Names the configuration key, never the credential it points at.
            $this->assertStringContainsString('services.cloudflare.account_id', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function it_creates_a_zone_in_the_configured_account(): void
    {
        config()->set('services.cloudflare.account_id', 'account-7');

        Http::fake([self::BASE.'/zones' => Http::response(self::ok(['id' => 'zone-new', 'name' => 'new.test']))]);

        $zone = $this->provider()->createZone('New.Test.');

        $this->assertSame('zone-new', $zone->id());

        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && ($request['name'] ?? null) === 'new.test'
                && ($request['account'] ?? null) === ['id' => 'account-7'];
        });
    }

    #[Test]
    public function publishing_a_record_that_is_not_there_creates_it(): void
    {
        Http::fake([
            self::BASE.'/zones/zone-1/dns_records?*' => Http::response(self::ok([])),
            self::BASE.'/zones/zone-1/dns_records' => Http::response(self::ok(['id' => 'rec-1'])),
        ]);

        $record = $this->provider()->publish(
            self::zone(),
            DnsRecord::of(DnsRecordType::A, 'www.lynomia.test', '192.0.2.10', 300),
        );

        $this->assertSame('rec-1', $record->id());

        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && ($request['type'] ?? null) === 'A'
                && ($request['name'] ?? null) === 'www.lynomia.test'
                && ($request['content'] ?? null) === '192.0.2.10'
                && ($request['ttl'] ?? null) === 300;
        });
    }

    #[Test]
    public function publishing_a_changed_record_replaces_it_rather_than_adding_a_second(): void
    {
        Http::fake([
            self::BASE.'/zones/zone-1/dns_records?*' => Http::response(self::ok([[
                'id' => 'rec-old', 'type' => 'A', 'name' => 'www.lynomia.test', 'content' => '192.0.2.9', 'ttl' => 300,
            ]])),
            self::BASE.'/zones/zone-1/dns_records/rec-old' => Http::response(self::ok(['id' => 'rec-old'])),
        ]);

        $this->provider()->publish(
            self::zone(),
            DnsRecord::of(DnsRecordType::A, 'www.lynomia.test', '192.0.2.10', 300),
        );

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'PUT' && str_ends_with($r->url(), '/rec-old'));
        Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'POST');
    }

    #[Test]
    public function publishing_a_record_the_zone_already_holds_writes_nothing(): void
    {
        Http::fake([
            self::BASE.'/zones/zone-1/dns_records?*' => Http::response(self::ok([[
                'id' => 'rec-1', 'type' => 'A', 'name' => 'www.lynomia.test', 'content' => '192.0.2.10', 'ttl' => 300,
            ]])),
        ]);

        $record = $this->provider()->publish(
            self::zone(),
            DnsRecord::of(DnsRecordType::A, 'www.lynomia.test', '192.0.2.10', 300),
        );

        $this->assertSame('rec-1', $record->id());

        // A write here would bump the zone serial and re-propagate an unchanged
        // record to every resolver that holds it.
        Http::assertNotSent(static fn (Request $r): bool => in_array($r->method(), ['POST', 'PUT'], true));
    }

    #[Test]
    public function an_mx_record_carries_its_priority_and_a_caa_record_carries_its_fields(): void
    {
        Http::fake([
            self::BASE.'/zones/zone-1/dns_records?*' => Http::response(self::ok([])),
            self::BASE.'/zones/zone-1/dns_records' => Http::response(self::ok(['id' => 'rec-2'])),
        ]);

        $provider = $this->provider();

        $provider->publish(self::zone(), DnsRecord::of(
            DnsRecordType::MX, 'lynomia.test', 'mail.lynomia.test', DnsRecord::AUTOMATIC_TTL, 10,
        ));

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST' && ($r['priority'] ?? null) === 10);

        $provider->publish(self::zone(), DnsRecord::of(
            type: DnsRecordType::CAA,
            name: 'lynomia.test',
            content: '0 issue "letsencrypt.org"',
            data: ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'],
        ));

        // CAA goes as three fields. Some providers accept the presentation
        // form as content and others silently mangle it.
        Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
            && ($r['data'] ?? null) === ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org']);
    }

    #[Test]
    public function deleting_a_record_that_is_not_there_is_not_an_error(): void
    {
        Http::fake([self::BASE.'/zones/zone-1/dns_records?*' => Http::response(self::ok([]))]);

        $this->provider()->delete(self::zone(), DnsRecord::of(DnsRecordType::A, 'gone.lynomia.test', '192.0.2.10'));

        // The caller asked for the name to be absent, and it is.
        Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'DELETE');
    }

    #[Test]
    public function a_refusal_is_reported_with_the_providers_reason_and_is_not_indeterminate(): void
    {
        Http::fake([
            self::BASE.'/zones/zone-1/dns_records?*' => Http::response(self::ok([])),
            self::BASE.'/zones/zone-1/dns_records' => Http::response([
                'success' => false,
                'errors' => [['code' => 81057, 'message' => 'Record already exists.']],
                'result' => null,
            ], 400),
        ]);

        try {
            $this->provider()->publish(self::zone(), DnsRecord::of(DnsRecordType::A, 'www.lynomia.test', '192.0.2.10'));
            $this->fail('A refusal was not reported.');
        } catch (DnsProviderException $e) {
            $this->assertFalse($e->isIndeterminate());
            $this->assertStringContainsString('81057', $e->getMessage());
            $this->assertStringContainsString('Record already exists', $e->getMessage());
        }
    }

    #[Test]
    public function a_two_hundred_carrying_success_false_is_still_a_refusal(): void
    {
        Http::fake([
            self::BASE.'/zones*' => Http::response([
                'success' => false,
                'errors' => [['code' => 10000, 'message' => 'Authentication error']],
                'result' => null,
            ], 200),
        ]);

        $this->expectException(DnsProviderException::class);

        $this->provider()->findZone('lynomia.test');
    }

    #[Test]
    public function a_call_that_never_came_back_is_indeterminate(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            $this->provider()->findZone('lynomia.test');
            $this->fail('A timeout was not reported.');
        } catch (DnsProviderException $e) {
            // Indeterminate, so nothing above this may retry it. A retried
            // create is how one name ends up with two records.
            $this->assertTrue($e->isIndeterminate());
        }
    }

    #[Test]
    public function a_credential_quoted_back_in_a_refusal_is_redacted(): void
    {
        Http::fake([
            self::BASE.'/zones*' => Http::response([
                'success' => false,
                'errors' => [['code' => 6003, 'message' => 'Invalid request headers: Authorization: Bearer '.self::TOKEN]],
            ], 400),
        ]);

        try {
            $this->provider()->findZone('lynomia.test');
            $this->fail('A refusal was not reported.');
        } catch (DnsProviderException $e) {
            // Providers quote the request back when they refuse it. This one
            // quoted the token, and the exception is what reaches the log.
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, json_encode($e->context(), JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function no_token_configured_is_reported_as_the_platforms_problem(): void
    {
        config()->set('services.cloudflare.api_token', '');

        try {
            $this->provider()->findZone('lynomia.test');
            $this->fail('A missing token was not reported.');
        } catch (DnsNotConfiguredException $e) {
            $this->assertSame('dns.not_configured', $e->errorCode());
            $this->assertSame(500, $e->httpStatus());
            $this->assertStringContainsString('services.cloudflare.api_token', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
