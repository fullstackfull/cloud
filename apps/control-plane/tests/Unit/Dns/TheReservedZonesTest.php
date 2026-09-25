<?php

declare(strict_types=1);

namespace Tests\Unit\Dns;

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
    }

    #[Test]
    #[DataProvider('urls')]
    public function a_platform_address_contributes_its_host_and_nothing_else(?string $url, ?string $expected): void
    {
        $reserved = new ReservedZones([], ['APP_URL' => $url]);

        $this->assertSame(['APP_URL' => $expected], $reserved->derivedByVariable());
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
