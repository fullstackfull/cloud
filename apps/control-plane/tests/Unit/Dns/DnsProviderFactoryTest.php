<?php

declare(strict_types=1);

namespace Tests\Unit\Dns;

use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsProviderException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDnsRecordException;
use Lynomia\Modules\Dns\Domain\Exceptions\UnknownDnsDriverException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsZone;
use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
use Lynomia\Modules\Dns\Infrastructure\Providers\CloudflareDnsProvider;
use Lynomia\Modules\Dns\Infrastructure\Providers\FakeDnsProvider;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Resolution, refusal, and the rules the value objects enforce before anything
 * reaches a provider at all.
 */
final class DnsProviderFactoryTest extends TestCase
{
    private function factory(): DnsProviderFactory
    {
        return new DnsProviderFactory($this->app->make(SecretRedactor::class));
    }

    #[Test]
    public function it_resolves_the_driver_configuration_names(): void
    {
        config()->set('billing.providers.dns', 'fake');
        $this->assertInstanceOf(FakeDnsProvider::class, $this->factory()->make());

        config()->set('billing.providers.dns', 'CloudFlare');
        // Case-folded: an operator who typed it with capitals configured
        // Cloudflare, and refusing them over that would be pedantry.
        $this->assertInstanceOf(CloudflareDnsProvider::class, $this->factory()->make());
    }

    #[Test]
    public function a_driver_this_build_does_not_contain_raises_rather_than_falling_back(): void
    {
        config()->set('billing.providers.dns', 'route53');

        try {
            $this->factory()->make();
            $this->fail('An unknown driver was resolved.');
        } catch (UnknownDnsDriverException $e) {
            // A silent fallback to the fake would report every record as
            // published while publishing nothing.
            $this->assertStringContainsString('route53', $e->getMessage());
            $this->assertStringContainsString('cloudflare', $e->getMessage());
        }
    }

    #[Test]
    public function the_fake_refuses_to_exist_in_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must never be constructed in production');

        new FakeDnsProvider;
    }

    #[Test]
    public function the_fake_is_idempotent_the_way_a_real_provider_is(): void
    {
        $provider = new FakeDnsProvider;
        $zone = $provider->withZone('lynomia.test');

        $first = $provider->publish($zone, DnsRecord::of(DnsRecordType::A, 'www.lynomia.test', '192.0.2.10'));
        $second = $provider->publish($zone, DnsRecord::of(DnsRecordType::A, 'www.lynomia.test', '192.0.2.11'));

        // One record, one identifier, whether it was written once or twice.
        $this->assertCount(1, $provider->records($zone, DnsRecordType::A));
        $this->assertSame($first->id(), $second->id());
        $this->assertSame('192.0.2.11', $provider->records($zone, DnsRecordType::A)[0]->content());
    }

    #[Test]
    public function the_fakes_timeout_marker_is_indeterminate_and_its_refusal_is_not(): void
    {
        $provider = new FakeDnsProvider;
        $zone = $provider->withZone('lynomia.test');

        try {
            $provider->publish($zone, DnsRecord::of(DnsRecordType::A, 'dns-timeout.lynomia.test', '192.0.2.10'));
            $this->fail('The timeout marker did not time out.');
        } catch (DnsProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
        }

        try {
            $provider->publish($zone, DnsRecord::of(DnsRecordType::A, 'dns-refused.lynomia.test', '192.0.2.10'));
            $this->fail('The refusal marker did not refuse.');
        } catch (DnsProviderException $e) {
            $this->assertFalse($e->isIndeterminate());
        }
    }

    #[Test]
    public function a_zone_covers_only_names_beneath_it_on_a_label_boundary(): void
    {
        $zone = DnsZone::of('z', 'example.test');

        $this->assertTrue($zone->covers('example.test'));
        $this->assertTrue($zone->covers('www.example.test'));

        // Somebody else's domain that happens to end in the same characters.
        $this->assertFalse($zone->covers('notexample.test'));
    }

    #[Test]
    public function a_record_that_cannot_be_valid_cannot_be_built(): void
    {
        // An MX with no priority: a provider would either reject it after a
        // round trip or invent a routing preference nobody chose.
        $this->expectException(InvalidDnsRecordException::class);

        DnsRecord::of(DnsRecordType::MX, 'lynomia.test', 'mail.lynomia.test');
    }

    #[Test]
    public function a_ttl_outside_what_a_zone_will_honour_is_refused_here(): void
    {
        $this->expectException(InvalidDnsRecordException::class);
        $this->expectExceptionMessage('must be 60-86400 seconds');

        DnsRecord::of(DnsRecordType::A, 'www.lynomia.test', '192.0.2.10', 5);
    }

    #[Test]
    public function the_automatic_ttl_is_allowed_through_unchanged(): void
    {
        $record = DnsRecord::of(DnsRecordType::A, 'www.lynomia.test', '192.0.2.10', DnsRecord::AUTOMATIC_TTL);

        $this->assertSame(DnsRecord::AUTOMATIC_TTL, $record->ttl());
    }
}
