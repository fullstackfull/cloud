<?php

declare(strict_types=1);

namespace Tests\Support;

use stdClass;

/**
 * A small structural checker for the JSON Schema `docs/openapi.yaml`
 * publishes, for tests that compare a real response with it.
 *
 * There is no JSON Schema validator among this application's dependencies,
 * and adding one is not a test's decision. This implements the keywords the
 * description uses and nothing else:
 *
 *  - `type` (a name or a list of names), with JSON's meaning: `object` is a
 *    decoded `stdClass` and `array` a PHP list, so a body must be decoded with
 *    `json_decode($body, false)` or `{}` and `[]` cannot be told apart;
 *    `integer` is a number with no fractional part, as 2020-12 says;
 *  - `$ref` to `#/components/schemas/<name>`, and to nothing else;
 *  - `properties`, `required`, `additionalProperties` (a boolean or a schema);
 *  - `items`, `minItems`, `maxItems`;
 *  - `enum`, `const`, `pattern` (as a PCRE with `u`), `minimum`, `maximum`,
 *    `minLength`, `maxLength`;
 *  - `oneOf` (exactly one), `anyOf` (at least one), `allOf` (every one).
 *
 * `format` is read as an annotation, as 2020-12 reads it by default, and so
 * are `description`, `examples`, `title`, `default`, `deprecated`,
 * `readOnly`, `writeOnly` and `x-*`. Any other keyword is reported as a
 * violation naming it rather than skipped, so a schema that starts using one
 * this does not implement fails the test that reads it instead of passing
 * it unread. It does not implement `$ref` outside components, `$defs`,
 * `if`/`then`/`else`, `not`, `patternProperties`, `dependentRequired` or
 * `uniqueItems`; the description uses none of them when this was written.
 */
final class PublishedSchema
{
    private const array ANNOTATIONS = [
        'description', 'examples', 'example', 'title', 'default', 'deprecated', 'readOnly', 'writeOnly', 'format',
    ];

    private const array KEYWORDS = [
        'type', '$ref', 'properties', 'required', 'additionalProperties', 'items', 'minItems', 'maxItems',
        'enum', 'const', 'pattern', 'minimum', 'maximum', 'minLength', 'maxLength', 'oneOf', 'anyOf', 'allOf',
    ];

    /**
     * @param  array<string, mixed>  $schemas  `components.schemas` of the description
     */
    public function __construct(private readonly array $schemas) {}

    /**
     * Every way `$value` is not what `$schema` publishes, each prefixed with
     * where in the value it is.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function violations(mixed $value, array $schema, string $at = '$'): array
    {
        $out = [];

        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, self::KEYWORDS, true) && ! in_array($keyword, self::ANNOTATIONS, true) && ! str_starts_with($keyword, 'x-')) {
                $out[] = "{$at}: the schema uses `{$keyword}`, which this checker does not implement";
            }
        }

        if (isset($schema['$ref'])) {
            $ref = (string) $schema['$ref'];

            if (! str_starts_with($ref, '#/components/schemas/') || ! isset($this->schemas[substr($ref, 21)])) {
                return [...$out, "{$at}: \$ref {$ref} names no component schema"];
            }

            /** @var array<string, mixed> $target */
            $target = $this->schemas[substr($ref, 21)];
            $out = [...$out, ...$this->violations($value, $target, $at)];
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];

            if (! array_any($types, fn (string $type): bool => self::is($type, $value))) {
                return [...$out, sprintf('%s: %s is not of type %s', $at, self::describe($value), implode('|', $types))];
            }
        }

        foreach (['oneOf', 'anyOf', 'allOf'] as $combinator) {
            if (! isset($schema[$combinator])) {
                continue;
            }

            /** @var list<array<string, mixed>> $branches */
            $branches = $schema[$combinator];
            $failures = array_map(fn (array $branch): array => $this->violations($value, $branch, $at), $branches);
            $matched = count(array_filter($failures, static fn (array $f): bool => $f === []));

            $ok = match ($combinator) {
                'oneOf' => $matched === 1,
                'anyOf' => $matched >= 1,
                'allOf' => $matched === count($branches),
            };

            if (! $ok) {
                $out[] = sprintf('%s: %s matches %d of the %d branches of %s (%s)', $at, self::describe($value), $matched, count($branches), $combinator, implode('; ', array_merge(...$failures)));
            }
        }

        if (isset($schema['enum']) && ! in_array($value, (array) $schema['enum'], true)) {
            $out[] = sprintf('%s: %s is not one of %s', $at, self::describe($value), json_encode($schema['enum']));
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $out[] = sprintf('%s: %s is not %s', $at, self::describe($value), json_encode($schema['const']));
        }

        if (is_string($value)) {
            if (isset($schema['pattern']) && preg_match('/'.str_replace('/', '\/', (string) $schema['pattern']).'/u', $value) !== 1) {
                $out[] = sprintf('%s: %s does not match %s', $at, self::describe($value), $schema['pattern']);
            }

            if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
                $out[] = "{$at}: shorter than {$schema['minLength']}";
            }

            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $out[] = "{$at}: longer than {$schema['maxLength']}";
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $out[] = "{$at}: {$value} is below {$schema['minimum']}";
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $out[] = "{$at}: {$value} is above {$schema['maximum']}";
            }
        }

        if (is_array($value) && array_is_list($value)) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $out[] = "{$at}: fewer than {$schema['minItems']} items";
            }

            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $out[] = "{$at}: more than {$schema['maxItems']} items";
            }

            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    $out = [...$out, ...$this->violations($item, $schema['items'], "{$at}[{$i}]")];
                }
            }
        }

        if ($value instanceof stdClass) {
            $present = get_object_vars($value);
            /** @var array<string, array<string, mixed>> $properties */
            $properties = $schema['properties'] ?? [];

            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists((string) $required, $present)) {
                    $out[] = "{$at}: the required property `{$required}` is missing";
                }
            }

            foreach ($present as $name => $item) {
                $name = (string) $name;

                if (isset($properties[$name])) {
                    $out = [...$out, ...$this->violations($item, $properties[$name], "{$at}.{$name}")];

                    continue;
                }

                $additional = $schema['additionalProperties'] ?? true;

                if ($additional === false) {
                    $out[] = "{$at}: `{$name}` is not a published property";
                } elseif (is_array($additional)) {
                    $out = [...$out, ...$this->violations($item, $additional, "{$at}.{$name}")];
                }
            }
        }

        return $out;
    }

    private static function is(string $type, mixed $value): bool
    {
        return match ($type) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => $value instanceof stdClass,
            default => false,
        };
    }

    private static function describe(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return get_debug_type($value).' '.(is_string($json) && strlen($json) > 60 ? substr($json, 0, 57).'...' : (string) $json);
    }
}
