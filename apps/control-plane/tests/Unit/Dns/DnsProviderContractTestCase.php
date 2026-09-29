<?php

declare(strict_types=1);

namespace Tests\Unit\Dns;

use Lynomia\Modules\Dns\Domain\Contracts\DnsProvider;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsZone;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What every forward-DNS provider must do with records, run against each.
 *
 * Written for F-11. The audit's sentence was that the Cloudflare adapter
 * collapsed every record at one `(type, name)` onto the first one it read —
 * destroying round-robin addresses and backup mail exchangers — and that the
 * simulator reproduced the defect, so no test could catch it. Both halves are
 * answered here at once: every case below runs against the Cloudflare adapter
 * (over `CloudflareZoneSimulator`) *and* against the
 * controlled fake, so an oracle that shares the adapter's mistake is red in
 * the same invocation rather than green beside it.
 *
 * A record's identity is what `DnsRecordIdentity` says it is: the provider's identifier when the record carries one the zone
 * still knows, and otherwise its value. Neither tier is `(type, name)`.
 */
abstract class DnsProviderContractTestCase extends TestCase
{
    abstract protected function provider(): DnsProvider;

    abstract protected function zone(): DnsZone;

    /**
     * Remove a record without the platform asking — a console delete.
     */
    abstract protected function removeBehindThePlatformsBack(string $id): void;

    private function a(string $address, int $ttl = 300, ?string $id = null): DnsRecord
    {
        return DnsRecord::of(DnsRecordType::A, 'www.lynomia.test', $address, $ttl, id: $id);
    }

    /**
     * @return list<string>
     */
    private function addressesAt(string $name): array
    {
        $held = array_map(
            static fn (DnsRecord $record): string => $record->content(),
            $this->provider()->records($this->zone(), DnsRecordType::A, $name),
        );
        sort($held);

        return $held;
    }

    #[Test]
    public function a_name_holds_as_many_address_records_as_were_published(): void
    {
        $first = $this->provider()->publish($this->zone(), $this->a('192.0.2.10'));
        $second = $this->provider()->publish($this->zone(), $this->a('192.0.2.11'));

        // Round robin. Two records, two identifiers.
        $this->assertSame(['192.0.2.10', '192.0.2.11'], $this->addressesAt('www.lynomia.test'));
        $this->assertNotSame($first->id(), $second->id());
    }

    #[Test]
    public function removing_one_address_of_a_round_robin_leaves_the_other_answering(): void
    {
        $first = $this->provider()->publish($this->zone(), $this->a('192.0.2.10'));
        $this->provider()->publish($this->zone(), $this->a('192.0.2.11'));

        $this->provider()->delete($this->zone(), $first);

        $this->assertSame(['192.0.2.11'], $this->addressesAt('www.lynomia.test'));
    }

    #[Test]
    public function removing_by_value_alone_takes_only_that_value(): void
    {
        $this->provider()->publish($this->zone(), $this->a('192.0.2.10'));
        $this->provider()->publish($this->zone(), $this->a('192.0.2.11'));

        $this->provider()->delete($this->zone(), $this->a('192.0.2.11'));

        $this->assertSame(['192.0.2.10'], $this->addressesAt('www.lynomia.test'));
    }

    #[Test]
    public function a_backup_mail_exchanger_survives_the_primary_being_republished_and_removed(): void
    {
        $primary = DnsRecord::of(DnsRecordType::MX, 'lynomia.test', 'mx1.lynomia.test', 300, 10);
        $backup = DnsRecord::of(DnsRecordType::MX, 'lynomia.test', 'mx2.lynomia.test', 300, 20);

        $primary = $this->provider()->publish($this->zone(), $primary);
        $this->provider()->publish($this->zone(), $backup);
        $this->provider()->publish($this->zone(), $primary);

        $this->assertCount(2, $this->provider()->records($this->zone(), DnsRecordType::MX, 'lynomia.test'));

        $this->provider()->delete($this->zone(), $primary);

        $left = $this->provider()->records($this->zone(), DnsRecordType::MX, 'lynomia.test');
        $this->assertCount(1, $left);
        $this->assertSame('mx2.lynomia.test', $left[0]->content());
        $this->assertSame(20, $left[0]->priority());
    }

    #[Test]
    public function one_host_at_two_priorities_is_two_records(): void
    {
        $this->provider()->publish($this->zone(), DnsRecord::of(DnsRecordType::MX, 'lynomia.test', 'mx.lynomia.test', 300, 10));
        $this->provider()->publish($this->zone(), DnsRecord::of(DnsRecordType::MX, 'lynomia.test', 'mx.lynomia.test', 300, 20));

        $this->assertCount(2, $this->provider()->records($this->zone(), DnsRecordType::MX, 'lynomia.test'));
    }

    #[Test]
    public function publishing_the_same_record_twice_leaves_one(): void
    {
        $first = $this->provider()->publish($this->zone(), $this->a('192.0.2.10'));
        $again = $this->provider()->publish($this->zone(), $this->a('192.0.2.10'));

        $this->assertSame($first->id(), $again->id());
        $this->assertSame(['192.0.2.10'], $this->addressesAt('www.lynomia.test'));
    }

    #[Test]
    public function a_record_published_with_its_identifier_changes_in_place_and_its_sibling_is_untouched(): void
    {
        $first = $this->provider()->publish($this->zone(), $this->a('192.0.2.10'));
        $this->provider()->publish($this->zone(), $this->a('192.0.2.11'));

        $changed = $this->provider()->publish($this->zone(), $this->a('192.0.2.12', id: $first->id()));

        $this->assertSame($first->id(), $changed->id());
        $this->assertSame(['192.0.2.11', '192.0.2.12'], $this->addressesAt('www.lynomia.test'));
    }

    #[Test]
    public function an_identifier_the_zone_no_longer_knows_falls_through_to_the_value(): void
    {
        $first = $this->provider()->publish($this->zone(), $this->a('192.0.2.10'));
        $this->provider()->publish($this->zone(), $this->a('192.0.2.11'));

        $this->removeBehindThePlatformsBack((string) $first->id());

        $republished = $this->provider()->publish($this->zone(), $this->a('192.0.2.10', id: $first->id()));

        $this->assertNotSame($first->id(), $republished->id());
        $this->assertSame(['192.0.2.10', '192.0.2.11'], $this->addressesAt('www.lynomia.test'));
    }

    #[Test]
    public function removing_by_an_identifier_the_zone_no_longer_knows_is_not_an_error_and_spares_the_sibling(): void
    {
        $first = $this->provider()->publish($this->zone(), $this->a('192.0.2.10'));
        $this->provider()->publish($this->zone(), $this->a('192.0.2.11'));

        $this->removeBehindThePlatformsBack((string) $first->id());

        // "Removing one that is not there is not an error": the caller asked
        // for this value to be absent, and it is.
        $this->provider()->delete($this->zone(), $first);

        $this->assertSame(['192.0.2.11'], $this->addressesAt('www.lynomia.test'));
    }

    #[Test]
    public function a_change_of_ttl_alone_reaches_the_zone(): void
    {
        $first = $this->provider()->publish($this->zone(), $this->a('192.0.2.10', 300));

        $this->provider()->publish($this->zone(), $this->a('192.0.2.10', 3600, $first->id()));

        $held = $this->provider()->records($this->zone(), DnsRecordType::A, 'www.lynomia.test');
        $this->assertCount(1, $held);
        $this->assertSame(3600, $held[0]->ttl());
    }

    #[Test]
    public function a_caa_record_reads_back_as_the_record_that_was_published(): void
    {
        $caa = DnsRecord::of(
            type: DnsRecordType::CAA,
            name: 'lynomia.test',
            content: '0 issue "letsencrypt.org"',
            ttl: 300,
            data: ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'],
        );

        $published = $this->provider()->publish($this->zone(), $caa);

        $held = $this->provider()->records($this->zone(), DnsRecordType::CAA, 'lynomia.test');
        $this->assertCount(1, $held);
        $this->assertTrue($held[0]->saysTheSameAs($caa), 'The zone reads back a CAA the platform cannot recognise as its own.');

        // And publishing it again is the same record, not a second one.
        $again = $this->provider()->publish($this->zone(), $caa);
        $this->assertSame($published->id(), $again->id());
        $this->assertCount(1, $this->provider()->records($this->zone(), DnsRecordType::CAA, 'lynomia.test'));
    }

    #[Test]
    public function a_zone_is_listed_whole_whatever_its_size(): void
    {
        // The platform's own ceiling (`dns.records_per_zone`), which is larger
        // than one page of a provider listing.
        for ($i = 1; $i <= 250; $i++) {
            $this->provider()->publish($this->zone(), DnsRecord::of(DnsRecordType::A, 'h'.$i.'.lynomia.test', '192.0.2.1', 300));
        }

        $this->assertCount(250, $this->provider()->records($this->zone()));
    }
}
