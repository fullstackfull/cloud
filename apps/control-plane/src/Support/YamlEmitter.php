<?php

declare(strict_types=1);

namespace Lynomia\Support;

use InvalidArgumentException;

/**
 * Writes the subset of YAML an OpenAPI document needs, and nothing else.
 *
 * Hand-written because this project has no YAML library: symfony/yaml is not
 * installed, and pulling a dependency in to serialise nested maps of scalars
 * would be a dependency to audit and update for the sake of a hundred lines.
 *
 * The subset is deliberately small — maps, lists, strings, integers, booleans,
 * null — and anything outside it raises rather than being guessed at. A
 * silently mis-serialised OpenAPI document is worse than no document: it
 * validates, it publishes, and it describes an API that does not exist.
 *
 * Strings are always double-quoted. Unquoted YAML scalars carry a surprising
 * amount of meaning — `yes`, `no`, `on`, `off`, `null`, `1.0`, a leading zero,
 * anything with a colon — and quoting everything removes that entire class of
 * bug at the cost of a slightly noisier file. Long descriptions use block
 * scalars, because a paragraph on one quoted line is a paragraph nobody reads
 * in a diff.
 */
final class YamlEmitter
{
    private const string INDENT = '  ';

    /**
     * @param  array<string, mixed>  $document
     */
    public static function emit(array $document): string
    {
        return self::map($document, 0);
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private static function map(array $map, int $depth): string
    {
        $out = '';
        $pad = str_repeat(self::INDENT, $depth);

        foreach ($map as $key => $value) {
            $renderedKey = $pad.self::key((string) $key).':';

            if ($value === []) {
                /*
                 * `[]`, not `{}`. PHP cannot tell an empty list from an empty
                 * map, and this file has to choose — so it chooses the one
                 * OpenAPI needs. Both places an empty collection appears in
                 * this document are lists: `security: []` meaning "this
                 * endpoint needs none", and `sessionCookie: []` meaning "this
                 * scheme has no scopes". A validator rejects `{}` in both.
                 *
                 * The other direction is written explicitly where it is
                 * wanted: a schema meaning "anything" is `additionalProperties:
                 * true`, not an empty map.
                 */
                $out .= $renderedKey." []\n";

                continue;
            }

            if (is_array($value) && array_is_list($value)) {
                $out .= $renderedKey."\n".self::list($value, $depth + 1);

                continue;
            }

            if (is_array($value)) {
                $out .= $renderedKey."\n".self::map($value, $depth + 1);

                continue;
            }

            if (is_string($value) && str_contains($value, "\n")) {
                $out .= $renderedKey." |-\n".self::block($value, $depth + 1);

                continue;
            }

            $out .= $renderedKey.' '.self::scalar($value)."\n";
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $list
     */
    private static function list(array $list, int $depth): string
    {
        $out = '';
        $pad = str_repeat(self::INDENT, $depth);

        foreach ($list as $item) {
            if (is_array($item) && $item !== [] && ! array_is_list($item)) {
                /*
                 * A map inside a list. Its first key sits on the dash line, so
                 * the map is rendered one level deeper and its leading
                 * indentation is replaced by the dash.
                 */
                $rendered = self::map($item, $depth + 1);
                $out .= $pad.'-'.substr($rendered, strlen($pad) + strlen(self::INDENT) - 1);

                continue;
            }

            if (is_array($item)) {
                throw new InvalidArgumentException('Nested lists and empty maps inside lists are not supported.');
            }

            $out .= $pad.'- '.self::scalar($item)."\n";
        }

        return $out;
    }

    private static function block(string $value, int $depth): string
    {
        $pad = str_repeat(self::INDENT, $depth);

        return implode('', array_map(
            static fn (string $line): string => rtrim($pad.$line)."\n",
            explode("\n", rtrim($value, "\n")),
        ));
    }

    private static function key(string $key): string
    {
        // Keys plain enough to leave bare stay bare, which keeps `paths:` and
        // `components:` readable. Anything else — a path, a status code — is
        // quoted.
        return preg_match('/\A[A-Za-z0-9_.\-]+\z/', $key) === 1 ? $key : self::quote($key);
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_string($value) => self::quote($value),
            default => throw new InvalidArgumentException(sprintf(
                'YamlEmitter cannot serialise a value of type %s.',
                get_debug_type($value),
            )),
        };
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\t"], ['\\\\', '\\"', '\\t'], $value).'"';
    }
}
