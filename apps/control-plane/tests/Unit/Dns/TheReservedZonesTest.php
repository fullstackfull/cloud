<?php

declare(strict_types=1);

namespace Tests\Unit\Dns;

use Lynomia\Modules\Dns\Domain\Enums\NoDerivedName;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDomainNameException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DomainName;
use Lynomia\Modules\Dns\Domain\ValueObjects\ReservedZones;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which names no account may hold, and what "hold" covers.
 *
 * Two halves, kept apart because they fail differently. What is reserved is a
 * question about configuration — a list somebody typed and the addresses the
 * platform already answers on. What a reservation covers is a question about
 * DNS: a name, every parent of it and everything beneath it, compared on label
 * boundaries and never on characters.
 */
final class TheReservedZonesTest extends TestCase
{
    #[Test]
    public function a_reserved_name_covers_itself_its_parents_and_everything_beneath_it(): void
    {
        $reserved = new ReservedZones(['panel.lynomia.test'], []);

        foreach (['panel.lynomia.test', 'lynomia.test', 'db.panel.lynomia.test', 'a.b.panel.lynomia.test'] as $name) {
            $this->assertTrue($reserved->protects(DomainName::fromString($name)), $name.' should be covered.');
        }
    }

    #[Test]
    public function a_sibling_or_a_name_that_only_ends_in_the_same_characters_is_not_covered(): void
    {
        $reserved = new ReservedZones(['panel.lynomia.test'], []);

        foreach (['api.lynomia.test', 'notpanel.lynomia.test', 'panel.lynomia.example', 'lynomia.example'] as $name) {
            $this->assertFalse($reserved->protects(DomainName::fromString($name)), $name.' should not be covered.');
        }
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string|null}>
     */
    public static function urls(): iterable
    {
        yield 'a host with a port and a path' => ['https://Panel.Lynomia.test:8443/api/v1', 'panel.lynomia.test'];
        yield 'a bare host' => ['https://lynomia.test', 'lynomia.test'];
        yield 'a host written with its root dot' => ['https://lynomia.test./', 'lynomia.test'];
        yield 'localhost, which is one label' => ['http://localhost:8000', null];
        yield 'an IPv4 literal' => ['http://203.0.113.10:8000', null];
        yield 'an IPv6 literal' => ['http://[2001:db8::1]:8000', null];
        yield 'nothing at all' => ['', null];
        yield 'unset' => [null, null];
        yield 'not a URL' => ['lynomia.test', null];
        yield 'written with space around it' => [" https://panel.lynomia.test \n", 'panel.lynomia.test'];

        /*
         * An internationalised host is held in the form DNS carries it — the
         * A-label a resolver is actually asked for. Left in Unicode it is not
         * a name the zone rules accept, and nothing would be held for it,
         * while its A-label is exactly what an account would type to claim it.
         */
        yield 'an internationalised host' => ['https://münchen.lynomia.test', 'xn--mnchen-3ya.lynomia.test'];
        yield 'an internationalised host in capitals' => ['https://MÜNCHEN.Lynomia.test', 'xn--mnchen-3ya.lynomia.test'];
        yield 'an internationalised name in every label' => ['https://لوحة.مثال.test', 'xn--ogbh2eq.xn--mgbh0fb.test'];
        yield 'an internationalised host written percent-encoded' => ['https://m%C3%BCnchen.lynomia.test', 'xn--mnchen-3ya.lynomia.test'];
        yield 'a character the older, transitional processing maps elsewhere' => ['https://straße.lynomia.test', 'xn--strae-oqa.lynomia.test'];
        yield 'an internationalised host that is not a name even in ASCII' => ['https://my_pänel.lynomia.test', null];
        yield 'an underscore' => ['https://my_panel.lynomia.test', null];
    }

    #[Test]
    #[DataProvider('urls')]
    public function a_platform_address_contributes_its_host_and_nothing_else(?string $url, ?string $expected): void
    {
        $reserved = new ReservedZones([], ['APP_URL' => $url]);

        $this->assertSame(['APP_URL' => $expected], $reserved->derivedByVariable());
    }

    /**
     * @return iterable<string, array{0: string|null, 1: NoDerivedName}>
     */
    public static function addressesThatContributeNothing(): iterable
    {
        yield 'unset' => [null, NoDerivedName::Unset];
        yield 'empty' => ['', NoDerivedName::Unset];
        yield 'blank' => ['   ', NoDerivedName::Unset];
        yield 'a host with no scheme in front of it' => ['panel.lynomia.test', NoDerivedName::NotAUrl];
        yield 'a scheme with no host after it' => ['https:///panel', NoDerivedName::NotAUrl];
        yield 'a host that is only the root' => ['https://./', NoDerivedName::NotAUrl];
        yield 'an IPv4 literal' => ['http://203.0.113.10:8000', NoDerivedName::IpAddress];
        yield 'an IPv4 literal in full-width digits' => ['http://２０３．０．１１３．１０/', NoDerivedName::IpAddress];
        yield 'an IPv6 literal' => ['http://[2001:db8::1]:8000', NoDerivedName::IpAddress];
        yield 'localhost' => ['http://localhost:8000', NoDerivedName::SingleLabel];
        yield 'an internationalised single label' => ['https://münchen', NoDerivedName::SingleLabel];
        yield 'an underscore' => ['https://my_panel.lynomia.test', NoDerivedName::NotADomainName];
        yield 'a number where the top-level label goes' => ['https://panel.123', NoDerivedName::NotADomainName];
        yield 'an internationalised host that is not a name even in ASCII' => ['https://my_pänel.lynomia.test', NoDerivedName::NotADomainName];
    }

    /**
     * Why an address gave nothing is what the report says about it, and what
     * it says is only true of the right case: "no zone at, above or beneath it
     * can be claimed" is true of an address and false of a host with no scheme
     * in front of it, whose name any account can claim.
     */
    #[Test]
    #[DataProvider('addressesThatContributeNothing')]
    public function an_address_that_contributes_nothing_says_why(?string $url, NoDerivedName $why): void
    {
        $reserved = new ReservedZones([], ['APP_URL' => $url, 'FRONTEND_URL' => 'https://portal.lynomia.test']);

        $this->assertSame(['APP_URL' => $why], $reserved->underived());
        $this->assertSame(['APP_URL' => null, 'FRONTEND_URL' => 'portal.lynomia.test'], $reserved->derivedByVariable());
    }

    #[Test]
    public function an_address_that_contributed_a_name_has_no_reason_against_it(): void
    {
        $reserved = new ReservedZones([], ['APP_URL' => 'https://münchen.lynomia.test', 'FRONTEND_URL' => 'https://portal.lynomia.test']);

        $this->assertSame([], $reserved->underived());
    }

    /**
     * What the report says about an address, held against the name rules it
     * rests on. If those rules ever let a zone at, above or beneath an address
     * through, this goes red rather than the report going on saying otherwise.
     */
    #[Test]
    public function no_zone_at_above_or_beneath_an_address_is_a_name(): void
    {
        foreach (['203.0.113.10', '[2001:db8::1]', '[::ffff:192.0.2.1]'] as $address) {
            $labels = explode('.', $address);
            $candidates = ['panel.'.$address];

            foreach (array_keys($labels) as $index) {
                $candidates[] = implode('.', array_slice($labels, $index));
            }

            foreach ($candidates as $candidate) {
                try {
                    DomainName::fromString($candidate);
                    $this->fail(sprintf('%s, at, above or beneath the address %s, reads as a name a zone could be claimed for.', $candidate, $address));
                } catch (InvalidDomainNameException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    #[Test]
    public function a_single_label_is_not_a_name_a_zone_could_be_claimed_for(): void
    {
        foreach (['localhost', 'devbox', 'xn--mnchen-3ya'] as $label) {
            try {
                DomainName::fromString($label);
                $this->fail(sprintf('The single label %s reads as a name a zone could be claimed for.', $label));
            } catch (InvalidDomainNameException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function what_is_derived_is_exactly_the_variables_that_contributed_something(): void
    {
        $reserved = new ReservedZones([], [
            'APP_URL' => 'http://localhost:8000',
            'FRONTEND_URL' => 'https://portal.lynomia.test',
        ]);

        $this->assertSame(['APP_URL' => null, 'FRONTEND_URL' => 'portal.lynomia.test'], $reserved->derivedByVariable());
        $this->assertSame(['FRONTEND_URL' => 'portal.lynomia.test'], $reserved->derived());
    }

    #[Test]
    public function the_whole_list_is_what_was_configured_and_what_was_derived(): void
    {
        $reserved = new ReservedZones([' Lynomia.test ', 'mail.lynomia.test'], [
            'APP_URL' => 'https://api.lynomia.test',
            'FRONTEND_URL' => 'http://localhost:5173',
        ]);

        $this->assertSame(
            ['lynomia.test', 'mail.lynomia.test', 'api.lynomia.test'],
            array_map(static fn (DomainName $name): string => $name->value(), $reserved->all()),
        );
    }

    #[Test]
    public function a_configured_entry_that_is_not_a_name_is_counted_and_never_skipped(): void
    {
        $reserved = new ReservedZones(['lynomia.test', 'not a name', 'localhost'], []);

        $this->assertSame(3, count($reserved->configured()));
        $this->assertSame(2, $reserved->malformed());

        /*
         * Skipping the entry would protect less than the operator asked for
         * and say nothing. The guard reads the whole list before it compares
         * anything, so the list either reads or the claim is refused.
         */
        $this->expectException(InvalidDomainNameException::class);

        $reserved->protects(DomainName::fromString('unrelated.test'));
    }

    #[Test]
    public function the_whole_list_is_read_before_a_listed_name_is_compared_with_the_claim(): void
    {
        /*
         * Order matters to what the customer is told, not only to whether
         * they are refused. Compared one entry at a time, a claim of the
         * first name here would be refused as reserved and never reach the
         * entry that does not read; read whole, every claim meets the same
         * failure, which is the state the preflight reports.
         */
        $reserved = new ReservedZones(['lynomia.test', 'not a name'], []);

        $this->expectException(InvalidDomainNameException::class);

        $reserved->protects(DomainName::fromString('lynomia.test'));
    }

    #[Test]
    public function an_empty_entry_is_not_an_entry(): void
    {
        // `DNS_RESERVED_ZONES=a.test,,b.test` and a trailing comma are
        // spellings of two names, not of three with a malformed one between.
        $reserved = new ReservedZones(['a.test', '', '  ', 'b.test'], []);

        $this->assertSame(['a.test', 'b.test'], $reserved->configured());
        $this->assertSame(0, $reserved->malformed());
    }

    #[Test]
    public function nothing_configured_and_nothing_derived_covers_nothing(): void
    {
        $reserved = new ReservedZones([], ['APP_URL' => 'http://localhost']);

        $this->assertSame([], $reserved->all());
        $this->assertFalse($reserved->protects(DomainName::fromString('lynomia.test')));
    }
}
