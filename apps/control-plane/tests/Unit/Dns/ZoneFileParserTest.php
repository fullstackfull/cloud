<?php

declare(strict_types=1);

namespace Tests\Unit\Dns;

use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Exceptions\ZoneFileRefusedException;
use Lynomia\Modules\Dns\Domain\Services\ZoneFileParser;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parser, against the shapes real exports arrive in and the shapes an
 * attacker would send.
 *
 * Nothing here touches a rule about DNS itself — a private address in an
 * A record is the rules' business, not the parser's. What the parser owns
 * is the grammar and the bounds: what a line means, and whether the input
 * is a zone file at all.
 */
final class ZoneFileParserTest extends TestCase
{
    private ZoneFileParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ZoneFileParser;
    }

    #[Test]
    public function a_typical_export_is_read_with_its_soa_and_apex_ns_set_aside(): void
    {
        $text = <<<'ZONE'
        ; exported from somewhere else
        $ORIGIN example.test.
        $TTL 3600
        @       IN  SOA   ns1.other.test. hostmaster.other.test. ( 2026090901 7200 3600 1209600 300 )
        @       IN  NS    ns1.other.test.
        @       IN  NS    ns2.other.test.
        @       IN  A     203.0.113.10
        www     300 IN  A     203.0.113.10
        mail        IN  AAAA  2001:db8::25
        @       IN  MX    10 mail
        @       IN  TXT   "v=spf1 mx -all"
        _dmarc  IN  TXT   ( "v=DMARC1; p=reject; "
                            "rua=mailto:dmarc@example.test" )
        blog    IN  CNAME www
        @       IN  CAA   0 issue "letsencrypt.org"
        ZONE;

        $zone = $this->parser->parse($text, 'example.test');

        $this->assertSame('example.test', $zone->origin);
        $this->assertSame([], $zone->refused);
        $this->assertCount(3, $zone->ignored);
        $this->assertStringContainsString('SOA', $zone->ignored[0]->reason);
        $this->assertStringContainsString('apex NS', $zone->ignored[1]->reason);

        $rows = array_map(static fn ($r): array => [$r->type->value, $r->name, $r->content, $r->ttl, $r->priority], $zone->records);

        $this->assertSame([
            ['A', 'example.test', '203.0.113.10', 3600, null],
            ['A', 'www.example.test', '203.0.113.10', 300, null],
            ['AAAA', 'mail.example.test', '2001:db8::25', 3600, null],
            ['MX', 'example.test', 'mail.example.test', 3600, 10],
            ['TXT', 'example.test', 'v=spf1 mx -all', 3600, null],
            ['TXT', '_dmarc.example.test', 'v=DMARC1; p=reject; rua=mailto:dmarc@example.test', 3600, null],
            ['CNAME', 'blog.example.test', 'www.example.test', 3600, null],
            ['CAA', 'example.test', '0 issue "letsencrypt.org"', 3600, null],
        ], $rows);

        $caa = $zone->records[7];
        $this->assertSame(['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'], $caa->data);
    }

    #[Test]
    public function without_a_ttl_directive_records_take_the_automatic_ttl_and_units_are_understood(): void
    {
        $zone = $this->parser->parse("www IN A 203.0.113.1\napi 1h IN A 203.0.113.2\ncdn IN 2d A 203.0.113.3\n\$TTL 30m\nx A 203.0.113.4", 'example.test');

        $this->assertSame([], $zone->refused);
        $this->assertSame(DnsRecord::AUTOMATIC_TTL, $zone->records[0]->ttl);
        $this->assertSame(3600, $zone->records[1]->ttl);
        $this->assertSame(172800, $zone->records[2]->ttl);
        $this->assertSame(1800, $zone->records[3]->ttl);
    }

    #[Test]
    public function a_blank_owner_continues_the_previous_one_and_names_are_lowercased(): void
    {
        $zone = $this->parser->parse("WWW IN A 203.0.113.1\n    IN A 203.0.113.2\nOther.Example.Test. IN A 203.0.113.3", 'example.test');

        $this->assertSame('www.example.test', $zone->records[0]->name);
        $this->assertSame('www.example.test', $zone->records[1]->name);
        $this->assertSame('other.example.test', $zone->records[2]->name);
    }

    #[Test]
    public function an_origin_outside_the_zone_is_refused_and_one_under_it_is_honoured(): void
    {
        $zone = $this->parser->parse("\$ORIGIN somebodyelse.test.\nwww IN A 203.0.113.1\n\$ORIGIN sub.example.test.\napp IN A 203.0.113.2", 'example.test');

        $this->assertCount(1, $zone->refused);
        $this->assertStringContainsString('$ORIGIN must be example.test', $zone->refused[0]->reason);
        // The record after the refused $ORIGIN was read against the last good origin.
        $this->assertSame('www.example.test', $zone->records[0]->name);
        $this->assertSame('app.sub.example.test', $zone->records[1]->name);
    }

    #[Test]
    public function include_generate_unknown_directives_and_unsupported_types_are_refused_with_the_line(): void
    {
        $zone = $this->parser->parse(implode("\n", [
            '$INCLUDE /etc/passwd',
            '$GENERATE 1-100 host-$ A 203.0.113.$',
            '$WHATEVER x',
            'srv IN SRV 10 5 5060 sip.example.test.',
            'ptr IN PTR host.example.test.',
            'sub IN NS ns1.sub.example.test.',
            'ok IN A 203.0.113.9',
        ]), 'example.test');

        $this->assertCount(1, $zone->records);
        $this->assertCount(6, $zone->refused);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_map(static fn ($p): int => $p->line, $zone->refused));
        $this->assertStringContainsString('file on somebody', $zone->refused[0]->reason);
        $this->assertStringContainsString('$GENERATE', $zone->refused[1]->reason);
        $this->assertStringContainsString('SRV records are not held', $zone->refused[3]->reason);
        // A delegation NS below the apex is a record type the platform does not hold, and says so.
        $this->assertStringContainsString('NS records are not held', $zone->refused[5]->reason);
    }

    #[Test]
    public function a_line_the_grammar_cannot_read_is_refused_and_the_rest_still_parses(): void
    {
        $zone = $this->parser->parse("www IN A\nmail IN MX mail.example.test.\nok IN A 203.0.113.9\ncaa IN CAA 0 issue", 'example.test');

        $this->assertCount(1, $zone->records);
        $this->assertCount(3, $zone->refused);
        $this->assertStringContainsString('exactly one address', $zone->refused[0]->reason);
        $this->assertStringContainsString('priority followed by one exchange', $zone->refused[1]->reason);
        $this->assertStringContainsString('flags, a tag and a quoted value', $zone->refused[2]->reason);
    }

    #[Test]
    public function quoted_strings_keep_semicolons_and_escapes_and_comments_stop_at_the_first_real_semicolon(): void
    {
        $zone = $this->parser->parse('@ IN TXT "a;b" "c\"d" ; a comment; with; semicolons', 'example.test');

        $this->assertSame([], $zone->refused);
        $this->assertSame('a;bc"d', $zone->records[0]->content);
        $this->assertSame(DnsRecordType::TXT, $zone->records[0]->type);
    }

    #[Test]
    public function the_input_is_refused_before_reading_when_it_is_too_big_too_long_or_not_text(): void
    {
        try {
            $this->parser->parse(str_repeat("www IN A 203.0.113.1\n", 20_000), 'example.test');
            $this->fail('A 400 KiB input was read.');
        } catch (ZoneFileRefusedException $e) {
            $this->assertSame('dns.zone_file.too_large', $e->errorCode());
        }

        try {
            $this->parser->parse(str_repeat("a\n", 5_001), 'example.test');
            $this->fail('5001 lines were read.');
        } catch (ZoneFileRefusedException $e) {
            $this->assertSame('dns.zone_file.too_large', $e->errorCode());
        }

        try {
            $this->parser->parse('www IN TXT "'.str_repeat('x', 5_000).'"', 'example.test');
            $this->fail('A 5000-character line was read.');
        } catch (ZoneFileRefusedException $e) {
            $this->assertSame('dns.zone_file.line_too_long', $e->errorCode());
        }

        try {
            $this->parser->parse("www IN A 203.0.113.1\x00", 'example.test');
            $this->fail('A NUL byte was read.');
        } catch (ZoneFileRefusedException $e) {
            $this->assertSame('dns.zone_file.not_text', $e->errorCode());
        }

        try {
            $this->parser->parse("www IN A 203.0.113.1 \xff\xfe", 'example.test');
            $this->fail('Invalid UTF-8 was read.');
        } catch (ZoneFileRefusedException $e) {
            $this->assertSame('dns.zone_file.not_text', $e->errorCode());
        }
    }

    #[Test]
    public function only_the_in_class_is_served(): void
    {
        $zone = $this->parser->parse("www CH A 203.0.113.1\nok IN A 203.0.113.2", 'example.test');

        $this->assertCount(1, $zone->refused);
        $this->assertStringContainsString('Only the IN class', $zone->refused[0]->reason);
        $this->assertCount(1, $zone->records);
    }

    #[Test]
    public function nothing_that_was_in_the_file_is_absent_from_every_list(): void
    {
        $text = "@ IN SOA a. b. 1 2 3 4 5\n@ IN NS ns.\nwww IN A 203.0.113.1\nbad IN SRV 1 1 1 x.\n; only a comment\n\n\$TTL 60\n";
        $zone = $this->parser->parse($text, 'example.test');

        // 8 lines: SOA (ignored), NS (ignored), A (record), SRV (refused), comment, blank, $TTL — everything meaningful accounted for.
        $this->assertSame(1, count($zone->records));
        $this->assertSame(1, count($zone->refused));
        $this->assertSame(2, count($zone->ignored));
    }
}
