<?php

declare(strict_types=1);

namespace Tests\Unit\Dns;

use Lynomia\Modules\Dns\Domain\Contracts\DnsProvider;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsProviderException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsZone;
use Lynomia\Modules\Dns\Infrastructure\Providers\CloudflareDnsProvider;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CloudflareZoneSimulator;

/**
 * The record contract, against the Cloudflare adapter over a simulated zone.
 *
 * The simulator is written by this repository, so a green here says the
 * adapter keeps the contract against the API as this repository models it —
 * not that Cloudflare agrees. The adapter's status stays TESTED.
 */
final class CloudflareAdapterKeepsTheRecordContractTest extends DnsProviderContractTestCase
{
    private CloudflareZoneSimulator $simulator;

    private ?CloudflareDnsProvider $provider = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->simulator = CloudflareZoneSimulator::install();
    }

    protected function provider(): DnsProvider
    {
        return $this->provider ??= new CloudflareDnsProvider($this->app->make(SecretRedactor::class));
    }

    protected function zone(): DnsZone
    {
        return DnsZone::of(CloudflareZoneSimulator::ZONE_ID, 'lynomia.test');
    }

    protected function removeBehindThePlatformsBack(string $id): void
    {
        $this->simulator->forget($id);
    }

    #[Test]
    public function a_record_the_zone_already_holds_exactly_is_not_written_again(): void
    {
        $record = DnsRecord::of(DnsRecordType::A, 'www.lynomia.test', '192.0.2.10', 300);

        $this->provider()->publish($this->zone(), $record);
        $writes = $this->simulator->writes();

        $this->provider()->publish($this->zone(), $record);

        // A write would bump the zone serial and re-propagate for nothing.
        $this->assertSame($writes, $this->simulator->writes());
    }

    #[Test]
    public function a_caa_record_the_zone_already_holds_is_not_rewritten_on_every_pass(): void
    {
        $caa = DnsRecord::of(
            type: DnsRecordType::CAA,
            name: 'lynomia.test',
            content: '0 issue "letsencrypt.org"',
            ttl: 300,
            data: ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'],
        );

        $published = $this->provider()->publish($this->zone(), $caa);
        $writes = $this->simulator->writes();

        $this->provider()->publish($this->zone(), $caa->withId((string) $published->id()));

        $this->assertSame($writes, $this->simulator->writes());
    }

    #[Test]
    public function a_listing_reads_every_page_and_not_only_the_first(): void
    {
        for ($i = 1; $i <= 250; $i++) {
            $this->simulator->holds(['type' => 'A', 'name' => 'h'.$i.'.lynomia.test', 'content' => '192.0.2.1', 'ttl' => 300]);
        }

        $this->assertCount(250, $this->provider()->records($this->zone()));
    }

    #[Test]
    public function a_publish_that_landed_unanswered_is_found_by_its_value_rather_than_made_twice(): void
    {
        $record = DnsRecord::of(DnsRecordType::A, 'www.lynomia.test', '192.0.2.10', 300);

        $this->simulator->loseTheNextAnswer();

        try {
            $this->provider()->publish($this->zone(), $record);
            $this->fail('The lost answer was not reported.');
        } catch (DnsProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
        }

        $this->provider()->publish($this->zone(), $record);

        $this->assertCount(1, $this->simulator->rows());
    }
}
