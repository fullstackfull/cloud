<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\ValueObjects;

use Normalizer;

/**
 * The one spelling of a login address: what is stored, and what every lookup
 * compares with.
 *
 * Trimmed, put in Unicode normal form C, lowercased with the full Unicode
 * mapping (mb_strtolower, UTF-8), put in form C again (lowercasing can
 * leave a sequence form C composes), and final sigma written as sigma.
 * Registration, sign-in, the reset endpoints and the rate limiter keyed on
 * them, both invitations and the console bootstrap each call this; the User
 * model applies it to every address it stores (User::email()), and so does
 * the team invitation model (CustomerInvitation::email()).
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
 * one address. Final sigma last: mb_strtolower() lowercases a capital Σ at
 * the end of a word to ς (U+03C2) and anywhere else to σ (U+03C3), so
 * `ΟΔΟΣ@…` became `οδος@…` while `οδοσ@…`, typed in lower case, stayed as it
 * was — one mailbox, two spellings (verifier of round eight). ς and σ are one
 * letter in lower case, so ς is written σ; that is case, not a compatibility
 * fold. Nothing else is folded: not compatibility forms (full-width letters
 * stay what they are) and not `ß` to `ss`, because two addresses those make
 * equal may be two mailboxes, and merging two logins is worse than keeping
 * two.
 *
 * Normalising a normalised address changed nothing in every case measured:
 * every code point in three contexts (the round-eight verifier's brute
 * force), 1,246,230 sequences of a code point from the Latin, Greek,
 * Cyrillic, Armenian, Georgian, Cherokee, Glagolitic or full-width Latin
 * blocks with one or two combining marks,
 * and the samples in OneMailboxIsOneLoginHoweverItsAddressIsWrittenTest,
 * one of which (`Ϊ` + U+0301) is why form C is applied a second time.
 */
final class LoginAddress
{
    public static function normalise(string $address): string
    {
        $trimmed = trim($address);
        $composed = Normalizer::normalize($trimmed, Normalizer::FORM_C);

        // false only for a string that is not valid UTF-8; lowercase what
        // there is rather than store it as written.
        $lower = mb_strtolower($composed === false ? $trimmed : $composed, 'UTF-8');

        // Lowercasing can leave a sequence form C would compose: `Ϊ` + U+0301
        // lowercases to `ϊ` + U+0301, which is `ΐ` in form C.
        $recomposed = $composed === false ? false : Normalizer::normalize($lower, Normalizer::FORM_C);

        return str_replace("\u{03C2}", "\u{03C3}", $recomposed === false ? $lower : $recomposed);
    }
}
