<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\ValueObjects;

use Normalizer;

/**
 * The one spelling of a login address: what is stored, and what every lookup
 * compares with.
 *
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
 * The address is trimmed and split at its last `@`, and the two sides are
 * treated differently, because they are delivered differently.
 *
 * ---------------------------------------------------------------------------
 * The local part
 * ---------------------------------------------------------------------------
 *
 * Put in Unicode normal form C, lowercased with the full Unicode mapping
 * (mb_strtolower, UTF-8), put in form C again, and ς written σ.
 *
 *  - Form C first, so that `Ä` typed as one code point and as `A` + U+0308
 *    are one address. Again after lowercasing, because lowercasing can leave
 *    a sequence form C composes: `Ϊ` + U+0301 lowercases to `ϊ` + U+0301,
 *    which is `ΐ` in form C.
 *  - ς (U+03C2) written σ (U+03C3): mb_strtolower() lowercases a capital Σ
 *    at the end of a word to ς and anywhere else to σ, so `ΟΔΟΣ@…` became
 *    `οδος@…` while `οδοσ@…`, typed in lower case, stayed as it was (verifier
 *    of round eight). Unicode's case folding maps ς to σ (CaseFolding.txt,
 *    `03C2; C; 03C3`): the two differ by case, which is what this function
 *    removes, and writing one as the other is not a compatibility fold.
 *
 * Nothing else is folded in the local part: not compatibility forms
 * (full-width letters stay what they are) and not `ß` to `ss` (a full, not a
 * common, case folding), because two addresses those make equal may be two
 * mailboxes, and merging two logins is worse than keeping two. The local
 * part's delivery is the receiving server's business; lowercasing it at all
 * is this platform's rule, not the mail system's.
 *
 * ---------------------------------------------------------------------------
 * The domain
 * ---------------------------------------------------------------------------
 *
 * A domain is not the platform's to compare as it likes: mail goes where the
 * resolver sends it, and an internationalised domain is resolved through
 * UTS #46 processing. So the domain, as typed, is put through exactly that
 * and nothing else: ICU's UTS #46 mapping and validation, non-transitional
 * (the IDNA2008 behaviour), to its ASCII form and back to Unicode
 * (idn_to_ascii / idn_to_utf8, INTL_IDNA_VARIANT_UTS46,
 * IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_NONTRANSITIONAL_TO_UNICODE). That
 * mapping lowercases and case-folds, puts the label in form C and maps
 * compatibility forms; the platform's own lowercasing (mb_strtolower) and
 * the local part's ς→σ are not applied to it. So two spellings stored the
 * same are one domain to a resolver, and two that resolve apart are stored
 * apart:
 *
 *  - ς is a deviation character under non-transitional processing, like ß:
 *    `οδος.gr` (xn--pxavbm.gr) and `οδοσ.gr` (xn--pxavbq.gr) are two domains,
 *    and stay two spellings here. Folding ς to σ in the domain, as the local
 *    part does, sent an operator's reset mail for `ops@οδος.gr` to
 *    `ops@οδοσ.gr` — whoever holds that domain (verifier of round eight, on
 *    f94cd63).
 *  - A capital Σ maps to σ under UTS #46 wherever it stands, so
 *    `ops@ΟΔΟΣ.gr` is stored `ops@οδοσ.gr`: that is the domain a resolver
 *    reaches for it (xn--pxavbq.gr), not `οδος.gr`. mb_strtolower() would
 *    have written ς at the end of a label that ends the address, and so
 *    named the other domain.
 *  - `ẞ` becomes `ß`, full-width letters ASCII, and an `xn--` label its
 *    Unicode form, each as ICU's UTS #46 mapping does.
 *
 * A domain UTS #46 refuses (an empty label, a leading hyphen, a disallowed
 * code point, …) is kept as typed except for ASCII letters, lowercased
 * (strtolower()), which UTS #46 would lowercase too: it is refused again on
 * a second pass, and no lowercasing or composing of ours turns it into a
 * domain that resolves.
 *
 * Measured, for every code point placed in four label contexts (4,448,124
 * domains): wherever UTS #46 accepts the domain as typed (593,791), the
 * stored domain has the same ASCII form; wherever it refuses it, it refuses
 * the stored one too.
 *
 * ---------------------------------------------------------------------------
 *
 * Normalising a normalised address changed nothing in every case measured:
 * every code point in five contexts, the domain included (the round-eight
 * verifier's brute force, norm2.php), 2,077,050 addresses built from a code
 * point of the Latin, Greek, Cyrillic, Armenian, Georgian, Cherokee,
 * Glagolitic or full-width Latin blocks with one or two combining marks, in
 * the local part and in the domain, and the samples in
 * OneMailboxIsOneLoginHoweverItsAddressIsWrittenTest.
 */
final class LoginAddress
{
    private const int IDNA_OPTIONS = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_NONTRANSITIONAL_TO_UNICODE;

    public static function normalise(string $address): string
    {
        $trimmed = trim($address);
        $at = strrpos($trimmed, '@');

        if ($at === false) {
            return self::localPart($trimmed);
        }

        return self::localPart(substr($trimmed, 0, $at)).'@'.self::domain(substr($trimmed, $at + 1));
    }

    private static function localPart(string $local): string
    {
        return str_replace("\u{03C2}", "\u{03C3}", self::lowercaseInFormC($local));
    }

    private static function domain(string $domain): string
    {
        if ($domain !== '') {
            $ascii = idn_to_ascii($domain, self::IDNA_OPTIONS, INTL_IDNA_VARIANT_UTS46);
            $unicode = $ascii === false ? false : idn_to_utf8($ascii, self::IDNA_OPTIONS, INTL_IDNA_VARIANT_UTS46);

            if (is_string($unicode)) {
                return $unicode;
            }
        }

        // Refused by UTS #46: only ASCII letters lowercased (strtolower()
        // touches nothing else), which UTS #46 does itself — so the result is
        // refused again, and a second pass leaves it as it is.
        return strtolower($domain);
    }

    /**
     * Form C, lowercased, form C again. A string that is not valid UTF-8 has
     * no form C; it is lowercased as it is rather than stored as written.
     */
    private static function lowercaseInFormC(string $value): string
    {
        $composed = Normalizer::normalize($value, Normalizer::FORM_C);
        $lower = mb_strtolower($composed === false ? $value : $composed, 'UTF-8');

        if ($composed === false) {
            return $lower;
        }

        $recomposed = Normalizer::normalize($lower, Normalizer::FORM_C);

        return $recomposed === false ? $lower : $recomposed;
    }
}
