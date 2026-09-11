<?php

declare(strict_types=1);

namespace Tests\Architecture;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every code the server sends a screen to say, in both languages.
 *
 * Wave 4 introduced a second kind of string the API hands the portal. The
 * first kind is an enum value, and `EveryStateAScreenShowsIsTranslatedTest`
 * has gated those since phase 30A+. These are different: the activity feed and
 * the attention list send *message codes* — `activity.vps.restarted`,
 * `attention.domain.lapsed` — which are translation keys the portal renders
 * directly, chosen in PHP by a `match` over source rows.
 *
 * They have exactly the failure mode the enum gate exists for, and no gate of
 * their own until now. A key the catalogue does not carry does not throw, does
 * not warn, and renders as itself: the browser suite found `attention.domain.lapsed`
 * at the top of the dashboard, in the first position, styled as a heading — a
 * dotted identifier where a sentence about a customer's expiring domain should
 * have been. Two of the eight attention codes were missing, in both languages,
 * and nothing in the build had noticed.
 *
 * So the codes are read out of the two classes that emit them, and every one
 * is looked up in both catalogues. A code added to either class fails this
 * test until somebody writes the two sentences a person will read.
 */
final class EveryMessageCodeIsTranslatedTest extends TestCase
{
    private const string LOCALES = __DIR__.'/../../../web/src/i18n/locales';

    /**
     * The files that decide what the server calls things, and the prefix each
     * one's codes must carry.
     *
     * A prefix rather than a bare scan, so that an unrelated dotted string in
     * one of these files — a config key, a route name — is not mistaken for a
     * message a customer will read.
     *
     * @var array<string, string>
     */
    private const array SOURCES = [
        'src/Modules/Activity/Application/Queries/ActivityProjection.php' => 'activity.',
        'src/Modules/Activity/Application/Queries/AccountAttention.php' => 'attention.',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function locales(): array
    {
        return ['English' => ['en.json'], 'Arabic' => ['ar.json']];
    }

    #[Test]
    #[DataProvider('locales')]
    public function every_message_code_has_a_sentence_in_this_language(string $file): void
    {
        $catalogue = self::catalogue($file);
        $missing = [];

        foreach (self::SOURCES as $relative => $prefix) {
            foreach (self::codesIn($relative, $prefix) as $code) {
                if (self::lookUp($catalogue, $code) === null) {
                    $missing[] = $code;
                }
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            sprintf(
                "%s is missing %d message code(s) the API sends:\n  %s",
                $file,
                count($missing),
                implode("\n  ", $missing),
            ),
        );
    }

    #[Test]
    public function the_codes_are_actually_being_found(): void
    {
        /*
         * A scan that matched nothing would pass both locales for ever. The
         * two classes between them emit well over twenty codes; ten is a floor
         * low enough never to need revisiting and high enough to fail if the
         * regex or a path stops matching.
         */
        $found = 0;

        foreach (self::SOURCES as $relative => $prefix) {
            $found += count(self::codesIn($relative, $prefix));
        }

        $this->assertGreaterThan(10, $found);
    }

    /**
     * Every `'<prefix>...'` literal in one file.
     *
     * @return list<string>
     */
    private static function codesIn(string $relative, string $prefix): array
    {
        $path = __DIR__.'/../../'.$relative;

        if (! is_file($path)) {
            throw new RuntimeException(sprintf('%s does not exist.', $relative));
        }

        preg_match_all(
            sprintf("/'(%s[A-Za-z0-9_.]+)'/", preg_quote($prefix, '/')),
            (string) file_get_contents($path),
            $matches,
        );

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function catalogue(string $file): array
    {
        $path = self::LOCALES.'/'.$file;

        if (! is_file($path)) {
            throw new RuntimeException(sprintf('The portal catalogue %s is missing.', $file));
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('%s is not valid JSON: %s', $file, $e->getMessage()));
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $catalogue
     */
    private static function lookUp(array $catalogue, string $code): ?string
    {
        /** @var mixed $value */
        $value = $catalogue;

        foreach (explode('.', $code) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            /** @var mixed $value */
            $value = $value[$segment];
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
