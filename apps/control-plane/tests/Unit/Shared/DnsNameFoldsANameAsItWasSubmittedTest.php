<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use Lynomia\Modules\Shared\Domain\Naming\DnsName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The fold a customer-submitted domain goes through, pinned byte for byte.
 *
 * ---------------------------------------------------------------------------
 * Why this is pinned rather than argued
 * ---------------------------------------------------------------------------
 *
 * The expression it replaces — `strtolower(trim($name, " \t\n\r\0\x0B."))` —
 * was written inline wherever a domain entered the platform. It is now
 * composed from {@see DnsName::canonical()} so the platform has one
 * lower-casing rule, and composing it is only safe if the result is the same
 * string for every input. The result feeds `orders.request_fingerprint`: a
 * fold that moved by one byte would move the fingerprint of every basket a
 * client is mid-way through retrying, and answer each of them 409.
 *
 * Twenty-two inputs, chosen for the edges: case, every character in the trim
 * set on each side and interleaved with dots, a root dot, doubled dots, inner
 * whitespace (which must survive, because it is `problemWith()`'s job to
 * refuse it), and two non-ASCII names. Those last two are the ones a future
 * change to multibyte lower-casing would move — and the database's CHECK
 * constraint computes `lower()` under a UTF-8 locale, so the application and
 * the column already disagree about them. That is latent only because every
 * producer runs `problemWith()`, which refuses non-ASCII, before it folds.
 */
final class DnsNameFoldsANameAsItWasSubmittedTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function names(): array
    {
        return [
            'plain' => ['example.test'],
            'upper case' => ['EXAMPLE.TEST'],
            'mixed case' => ['Shop.Example.Test'],
            'one root dot' => ['example.test.'],
            'two root dots' => ['example.test..'],
            'leading dot' => ['.example.test'],
            'leading dots' => ['...example.test'],
            'surrounding spaces' => ['  example.test  '],
            'tab and newline' => ["\texample.test\n"],
            'carriage return' => ["example.test\r"],
            'vertical tab' => ["\x0Bexample.test\x0B"],
            'nul' => ["\0example.test\0"],
            'dots and spaces interleaved' => [' . example.test . '],
            'dot then space then dot' => ['.\t.Example.Test.\n.'],
            'inner space survives' => ['exa mple.test'],
            'inner double dot survives' => ['example..test'],
            'only dots' => ['...'],
            'only whitespace' => [" \t\n"],
            'empty' => [''],
            'digits' => ['1.00'],
            'non-ascii lower' => ['café.test'],
            'non-ascii upper' => ['CAFÉ.TEST'],
        ];
    }

    #[Test]
    #[DataProvider('names')]
    public function the_fold_is_the_expression_it_replaced_to_the_byte(string $submitted): void
    {
        $this->assertSame(
            strtolower(trim($submitted, " \t\n\r\0\x0B.")),
            DnsName::canonicalAsSubmitted($submitted),
        );
    }

    #[Test]
    #[DataProvider('names')]
    public function folding_twice_is_folding_once(string $submitted): void
    {
        $once = DnsName::canonicalAsSubmitted($submitted);

        $this->assertSame($once, DnsName::canonicalAsSubmitted($once));
    }

    #[Test]
    public function it_is_the_canonical_name_for_everything_problem_with_accepts(): void
    {
        foreach (['Shop.Example.Test', 'shop.example.test.', ' shop.example.test '] as $name) {
            $this->assertNull(DnsName::problemWith($name));
            $this->assertSame(DnsName::canonical($name), DnsName::canonicalAsSubmitted($name));
        }
    }
}
