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
 * domain that resolves. So is a domain UTS #46 accepts but maps to
 * something holding an `@`, a separator or a control character: `＠`
 * (U+FF20) and `﹫` (U+FE6B) map to `@`, which would have moved the stored
 * address's last `@` (`ops@company.test＠evil.test` stored as
 * `ops@company.test@evil.test`), and a no-break or other Unicode space maps
 * to U+0020, which trim() took off the end on a second pass (verifier of
 * round eight, on 2698318). Kept as typed, such an address splits where it
 * was written and normalises the same twice; splitsWhereWritten() says it
 * would not be delivered where it is written, and every route where an
 * address is first taken in refuses it for that
 * (Lynomia\Http\Rules\ALoginAddressThatSplitsWhereWritten).
 *
 * Measured, for every code point placed in four label contexts (4,448,124
 * domains), the stored address read at its own last `@`: wherever UTS #46
 * accepts the domain as typed and maps it to no `@`, separator or control
 * character (593,507), the stored domain has the same ASCII form; where it
 * maps it to one (284), splitsWhereWritten() refuses every one; wherever
 * UTS #46 refuses the domain as typed, it refuses the stored one too. The
 * address as a whole is trimmed (trim()) before any of this, so an ASCII
 * space at its end is not part of the domain.
 *
 * ---------------------------------------------------------------------------
 * ext-intl
 * ---------------------------------------------------------------------------
 *
 * The spellings described here are ICU's: Normalizer and idn_to_ascii() /
 * idn_to_utf8() from ext-intl (ICU 74.2 where this was measured). Without
 * the extension nothing fails — symfony/polyfill-intl-normalizer and
 * symfony/polyfill-intl-idn, installed because symfony/mime, symfony/string
 * and egulias/email-validator require them, define both — but the
 * polyfill's IDNA tables are not ICU's, and a domain could be stored in
 * another spelling. The extension is
 * provisioned by the php_fpm role (`php_fpm_extensions` in
 * infrastructure/ansible/roles/php_fpm/defaults/main.yml) and named in the
 * setup-php steps of .github/workflows/ci.yml, and
 * TheLoginAddressSpellingHasTheExtensionItIsWrittenAgainstTest goes red
 * wherever the suite runs without it. composer.json does not require it;
 * adding that is a dependency change, proposed rather than made here.
 *
 * ---------------------------------------------------------------------------
 *
 * Normalising a normalised address changed nothing in every case measured:
 * every code point in five contexts, the domain included, and in nine,
 * five of them in the domain (the round-eight verifier's brute forces,
 * norm2.php and norm3.php), 2,077,050 addresses built from a code
 * point of the Latin, Greek, Cyrillic, Armenian, Georgian, Cherokee,
 * Glagolitic or full-width Latin blocks with one or two combining marks, in
 * the local part and in the domain, and the samples in
 * OneMailboxIsOneLoginHoweverItsAddressIsWrittenTest.
 */
final class LoginAddress
{
    private const int IDNA_OPTIONS = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_NONTRANSITIONAL_TO_UNICODE;

    /** An `@`, a separator (\p{Z}) or a control character (\p{Cc}): between them, every byte trim() strips. */
    private const string SPLITS_OR_TRIMS = '/[@\p{Z}\p{Cc}]/u';

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

            // A mapping that holds an `@` or a space is not stored: `＠`
            // (U+FF20) and `﹫` (U+FE6B) map to `@`, which would move the
            // address's last `@`, and a no-break or other Unicode space maps
            // to U+0020, which trim() would take off the end on a second pass.
            if (is_string($unicode) && preg_match(self::SPLITS_OR_TRIMS, $unicode) !== 1) {
                return $unicode;
            }
        }

        // Refused by UTS #46, or mapped to something that would split or
        // trim differently: only ASCII letters lowercased (strtolower()
        // touches nothing else), which UTS #46 does itself — so a second pass
        // takes this same path and leaves it as it is.
        return strtolower($domain);
    }

    /**
     * Whether the address would be delivered where it is written: it has an
     * `@`, and the compatibility form (NFKC) of its domain as typed — which
     * is where UTS #46's mapping of these comes from, and which keeps any
     * separator or control character the domain holds — has no `@`, no
     * separator (\p{Z}) and no control character (\p{Cc}).
     *
     * That is exactly the harm found: `＠` (U+FF20) and `﹫` (U+FE6B), whose
     * compatibility form and UTS #46 mapping is `@` (`ops@company.test＠evil.test`
     * read at its last `@` once mapped names evil.test), and a no-break or
     * other Unicode space, whose form is U+0020 — all accepted by
     * `email:rfc,strict`, all kept as typed by normalise() (verifier of round
     * eight, on 2698318). Nothing else is asked of the domain: one UTS #46
     * refuses — `--` in a label's third and fourth positions,
     * `x@mail.ab--cd.example.com`, `u@xn--bad.com` — receives mail, and is
     * not refused here (verifier of round eight, on 9bbf092, where this
     * required UTS #46 to accept it).
     *
     * The rule where an address is first taken in
     * (Lynomia\Http\Rules\ALoginAddressThatSplitsWhereWritten) refuses what
     * this refuses.
     *
     * Measured, for every code point placed in four label contexts (4,448,124
     * domains): of those UTS #46 accepts, this refuses the 284 whose mapping
     * holds an `@`, a separator or a control character and none other; of
     * those UTS #46 refuses (3,854,333), it refuses 140, each with a C1
     * control (U+0080–U+009F), the Ogham space mark (U+1680) or a line or
     * paragraph separator (U+2028, U+2029) standing in the domain as typed.
     */
    public static function splitsWhereWritten(string $address): bool
    {
        $trimmed = trim($address);
        $at = strrpos($trimmed, '@');

        if ($at === false) {
            return false;
        }

        $typed = substr($trimmed, $at + 1);
        $compatible = Normalizer::normalize($typed, Normalizer::FORM_KC);

        return preg_match(self::SPLITS_OR_TRIMS, $compatible === false ? $typed : $compatible) !== 1;
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
