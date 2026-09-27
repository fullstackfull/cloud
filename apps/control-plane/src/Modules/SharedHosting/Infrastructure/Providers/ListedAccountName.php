<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure\Providers;

/**
 * Whether one value out of a panel's account listing names exactly one account.
 *
 * Both adapters read their listing through this, so "one account name" means
 * the same thing whichever panel a node runs. A listing with any element this
 * refuses is refused whole by the adapter: dropping the element instead makes
 * a live account look missing, which is Critical drift and an alert about data
 * loss that did not happen.
 *
 * A name is one name when, trimmed, it is non-empty, holds no whitespace, no
 * control character and none of the separators a list could be joined with
 * (`,` `;` `|`), and is valid UTF-8 — bytes that cannot be read as text cannot
 * be shown to hold none of those either.
 *
 * Deliberately not a rule about what an account name on either panel may be.
 * Nobody here has established that, and the names the platform itself makes
 * are not a bound on what a panel holds: an operator makes accounts by hand,
 * and a requested username is passed through to the panel as asked. Case,
 * length, digits and punctuation other than those separators are all read as
 * a name, and the comparison decides whether it is one the platform knows.
 */
final class ListedAccountName
{
    /**
     * The value as one account name, or null when it is not one.
     *
     * Only a string is a name; an integer is read as the digits it is written
     * with. Anything else — an array, an object, a boolean, null — is not.
     */
    public static function from(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $name = trim($value);

        if ($name === '' || preg_match('/[\s\p{Cc},;|]/u', $name) === 1 || preg_match('//u', $name) !== 1) {
            return null;
        }

        return $name;
    }
}
