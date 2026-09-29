<?php

declare(strict_types=1);

namespace Tests\Unit\Dns;

use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Every field of a record is either compared or deliberately not, and says
 * which.
 *
 * Written for F-11. A TTL-only edit never reached the provider: the adapter's
 * idempotence short-circuit asked `saysTheSameAs()`, which excludes TTL on
 * purpose, so the write was skipped and the row was stamped published. The
 * two questions are different — "is this the same record?" and "does the
 * zone already hold exactly this?" — and this table says, for each field,
 * which of them it answers.
 *
 * The field list is reflected from the class's **properties**, not its
 * constructor's parameters, so a field assigned in a constructor body rather
 * than promoted is still seen. A new field with no row here fails the first
 * test, which is the point: the next field is decided on purpose.
 */
final class EveryFieldOfADnsRecordTest extends TestCase
{
    /**
     * field => [part of the record's identity?, part of what is published?]
     *
     * @return array<string, array{0: bool, 1: bool}>
     */
    private static function fields(): array
    {
        return [
            'type' => [true, true],
            'name' => [true, true],
            'content' => [true, true],
            'priority' => [true, true],
            'data' => [true, true],
            // How long resolvers keep it, not what it says: two records at one
            // value and two TTLs are one record, but a TTL change must be
            // written.
            'ttl' => [false, true],
            // The provider's handle on the record, not a fact about it.
            'id' => [false, false],
        ];
    }

    #[Test]
    public function every_property_is_in_the_table(): void
    {
        $properties = array_map(
            static fn (ReflectionProperty $p): string => $p->getName(),
            (new ReflectionClass(DnsRecord::class))->getProperties(),
        );
        sort($properties);

        $declared = array_keys(self::fields());
        sort($declared);

        $this->assertSame($declared, $properties, 'A field of DnsRecord was added or removed without deciding whether it is compared.');
    }

    #[Test]
    public function each_field_answers_the_questions_the_table_says_it_does(): void
    {
        $base = DnsRecord::of(DnsRecordType::MX, 'lynomia.test', 'mx.lynomia.test', 300, 10, ['k' => 'v'], 'rec-1');

        $variants = [
            'type' => DnsRecord::of(DnsRecordType::CNAME, 'lynomia.test', 'mx.lynomia.test', 300, 10, ['k' => 'v'], 'rec-1'),
            'name' => DnsRecord::of(DnsRecordType::MX, 'other.lynomia.test', 'mx.lynomia.test', 300, 10, ['k' => 'v'], 'rec-1'),
            'content' => DnsRecord::of(DnsRecordType::MX, 'lynomia.test', 'mx2.lynomia.test', 300, 10, ['k' => 'v'], 'rec-1'),
            'priority' => DnsRecord::of(DnsRecordType::MX, 'lynomia.test', 'mx.lynomia.test', 300, 20, ['k' => 'v'], 'rec-1'),
            'data' => DnsRecord::of(DnsRecordType::MX, 'lynomia.test', 'mx.lynomia.test', 300, 10, ['k' => 'w'], 'rec-1'),
            'ttl' => DnsRecord::of(DnsRecordType::MX, 'lynomia.test', 'mx.lynomia.test', 3600, 10, ['k' => 'v'], 'rec-1'),
            'id' => DnsRecord::of(DnsRecordType::MX, 'lynomia.test', 'mx.lynomia.test', 300, 10, ['k' => 'v'], 'rec-2'),
        ];

        foreach (self::fields() as $field => [$identity, $published]) {
            $this->assertSame(! $identity, $base->saysTheSameAs($variants[$field]), $field.': saysTheSameAs() disagrees with the table');
            $this->assertSame(! $published, $base->isPublishedExactlyAs($variants[$field]), $field.': isPublishedExactlyAs() disagrees with the table');
        }
    }

    #[Test]
    public function a_hostname_differing_only_in_case_is_the_same_value_and_text_is_not(): void
    {
        $this->assertTrue(
            DnsRecord::of(DnsRecordType::MX, 'lynomia.test', 'Mail.Lynomia.test', 300, 10)
                ->saysTheSameAs(DnsRecord::of(DnsRecordType::MX, 'lynomia.test', 'mail.lynomia.test', 300, 10)),
        );

        // A DKIM key or a verification token is case-sensitive, byte for byte.
        $this->assertFalse(
            DnsRecord::of(DnsRecordType::TXT, 'lynomia.test', 'p=abcDEF')
                ->saysTheSameAs(DnsRecord::of(DnsRecordType::TXT, 'lynomia.test', 'p=ABCdef')),
        );
    }
}
