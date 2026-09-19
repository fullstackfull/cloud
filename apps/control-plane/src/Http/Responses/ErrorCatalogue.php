<?php

declare(strict_types=1);

namespace Lynomia\Http\Responses;

use Illuminate\Support\Facades\Lang;

/**
 * The customer's sentence for an error code, in the request's language.
 *
 * `lang/{locale}/errors.php` is the canonical customer error catalogue: one
 * entry per code a customer can be answered with, in every language the
 * platform serves. The exception that raised the code keeps its own English
 * message — that message is written for the engineer reading a log, and it is
 * allowed to name a node, a driver or a provider. This class is the boundary
 * between the two: a code with a catalogue entry is answered with the
 * catalogue's sentence, and the exception's prose never reaches the response.
 *
 * A code without an entry falls back to the sentence it was given. That
 * fallback exists for the operator API, whose modules are not in the
 * catalogue and whose readers are staff; on the customer surface the parity
 * test in tests/Feature/Api/CustomerErrorCatalogueTest.php makes the fallback
 * unreachable by requiring an entry for every code the customer modules can
 * raise.
 *
 * Context values are offered to the sentence as `:placeholders`. Only what the
 * sentence names is used, so a context that carries an internal identifier
 * for the log's benefit does not surface unless the catalogue author asked
 * for it.
 */
final class ErrorCatalogue
{
    public static function has(string $code): bool
    {
        return Lang::has(self::key($code));
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function message(string $code, array $context = [], string $fallback = ''): string
    {
        if (! self::has($code)) {
            return $fallback !== '' ? $fallback : (string) __('errors.request_failed');
        }

        $replace = [];
        foreach ($context as $name => $value) {
            if ($value === null || is_bool($value)) {
                continue;
            }

            $replace[$name] = (string) $value;
        }

        $line = __(self::key($code), $replace);

        return is_string($line) ? $line : $fallback;
    }

    private static function key(string $code): string
    {
        return 'errors.'.$code;
    }
}
