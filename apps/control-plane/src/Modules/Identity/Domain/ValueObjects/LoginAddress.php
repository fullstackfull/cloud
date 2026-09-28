<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\ValueObjects;

use Normalizer;

/**
 * The one spelling of a login address: what is stored, and what every lookup
 * compares with.
 *
 * Trimmed, put in Unicode normal form C, then lowercased with the full
 * Unicode mapping (mb_strtolower, UTF-8). Registration, sign-in, the reset
 * endpoints and the rate limiter keyed on them, both invitations and the
 * console bootstrap each call this; the User model applies it to every
 * address it stores (User::email()).
 *
 * It exists because they used to disagree (R4, verifier of round eight).
 * Registration used strtolower(), which lowercases ASCII only, and the
 * operator invitation Str::lower(), which lowercases everything: `Ärger@…`
 * was stored as written, the invitation of `ärger@…` found no login and made
 * a second one, and the registrant kept theirs — the squatter case
 * InviteOperator exists to close, by another spelling. `email:rfc,strict`
 * accepts a non-ASCII local part and domain, so the spelling reaches here.
 *
 * Form C first, so that `Ä` typed as one code point and as `A` + U+0308 are
 * one address. Nothing is folded beyond case: not compatibility forms
 * (full-width letters stay what they are) and not `ß` to `ss`, because two
 * addresses those make equal may be two mailboxes, and merging two logins is
 * worse than keeping two.
 */
final class LoginAddress
{
    public static function normalise(string $address): string
    {
        $trimmed = trim($address);
        $composed = Normalizer::normalize($trimmed, Normalizer::FORM_C);

        // false only for a string that is not valid UTF-8; lowercase what
        // there is rather than store it as written.
        return mb_strtolower($composed === false ? $trimmed : $composed, 'UTF-8');
    }
}
