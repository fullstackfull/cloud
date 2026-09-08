<?php

declare(strict_types=1);

namespace Tests\Unit\Domains;

use Lynomia\Modules\Domains\Domain\Exceptions\InvalidDomainException;
use Lynomia\Modules\Domains\Domain\ValueObjects\RegistrableDomain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What can be bought, and what only looks like it can.
 *
 * The refusals here are the valuable half. Every one of them is a string a
 * customer might type that a registrar would reject after the money moved, so
 * catching it in a value object is the difference between a validation message
 * and a refund.
 */
final class RegistrableDomainTest extends TestCase
{
    /** @var list<string> */
    private const TLDS = ['com', 'net', 'co.uk', 'uk', 'sy', 'com.sy'];

    #[Test]
    public function an_ordinary_name_splits_into_its_label_and_its_namespace(): void
    {
        $domain = RegistrableDomain::parse('example.com', self::TLDS);

        $this->assertSame('example.com', $domain->name);
        $this->assertSame('com', $domain->tld);
        $this->assertSame('example', $domain->label);
    }

    #[Test]
    public function the_longest_matching_namespace_wins(): void
    {
        /*
         * Both `uk` and `co.uk` are sold, and the registration is under the
         * longer one. A rule that took everything after the last dot would try
         * to sell `uk` itself; one that took two labels would refuse `.com`.
         */
        $domain = RegistrableDomain::parse('example.co.uk', self::TLDS);

        $this->assertSame('co.uk', $domain->tld);
        $this->assertSame('example', $domain->label);
    }

    #[Test]
    public function case_and_a_trailing_dot_are_normalised_rather_than_refused(): void
    {
        // A trailing dot is a correctly-formed fully-qualified name and a
        // typo people who know DNS make.
        $domain = RegistrableDomain::parse('  ExAmPlE.CoM.  ', self::TLDS);

        $this->assertSame('example.com', $domain->name);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'empty' => ['   ', 'domain.name_empty'],
            'no ending at all' => ['example', 'domain.name_has_no_tld'],
            'an ending nobody here sells' => ['example.example', 'domain.tld_unknown'],
            // The one customers try most: buying a name inside somebody
            // else's registration.
            'a subdomain' => ['www.example.com', 'domain.name_not_second_level'],
            'the namespace by itself' => ['.com', 'domain.name_not_second_level'],
            'a leading hyphen' => ['-example.com', 'domain.name_malformed'],
            'a trailing hyphen' => ['example-.com', 'domain.name_malformed'],
            'an underscore' => ['ex_ample.com', 'domain.invalid_name'],
            'arabic letters' => ['مثال.com', 'domain.invalid_name'],
            'a punycoded label' => ['xn--mgbh0fb.com', 'domain.name_not_ascii'],
        ];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function a_string_that_is_not_for_sale_is_refused_with_a_reason(string $input, string $code): void
    {
        try {
            RegistrableDomain::parse($input, self::TLDS);
            $this->fail(sprintf('%s should not have parsed.', $input));
        } catch (InvalidDomainException $e) {
            /*
             * Asserted on the code rather than the message, because the code
             * is what the portal translates and the message is what changes
             * when somebody rewords it.
             *
             * Two inputs land on the generic code by design: the ASCII check
             * runs before the labels are split, so anything with a character
             * outside the set is refused as a whole rather than diagnosed.
             */
            $this->assertContains($e->errorCode(), [$code, 'domain.name_not_ascii']);
        }
    }

    #[Test]
    public function a_label_longer_than_a_registry_accepts_is_refused(): void
    {
        $this->expectException(InvalidDomainException::class);

        RegistrableDomain::parse(str_repeat('a', 64).'.com', self::TLDS);
    }

    #[Test]
    public function the_refusal_carries_no_copy_of_what_was_typed(): void
    {
        /*
         * A domain search is not private in the way a password is, but the
         * platform's rule is that names do not travel into logs and contexts,
         * and an exception context is one keystroke from a log line. The
         * length is enough to debug with.
         */
        try {
            RegistrableDomain::parse('www.something-a-customer-typed.com', self::TLDS);
            $this->fail('should have refused');
        } catch (InvalidDomainException $e) {
            $this->assertSame(['length' => 34], $e->context());
        }
    }
}
