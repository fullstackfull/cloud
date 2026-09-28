<?php

declare(strict_types=1);

namespace Lynomia\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;

/**
 * A login address that splits where it is written
 * (LoginAddress::splitsWhereWritten()): it has an `@`, and the
 * compatibility form (NFKC) of its domain holds no `@`, no separator and no
 * control character.
 *
 * `email:rfc,strict` accepts a full-width or small commercial at (U+FF20,
 * U+FE6B) and Unicode spaces in the domain. Their compatibility forms, which
 * UTS #46 maps them to, are `@` and a space: `ops@company.test＠evil.test`,
 * read at its last `@` once mapped, names evil.test (verifier of round eight,
 * on 2698318). This refuses them, with one sentence
 * (validation.requests.login_address.not_where_written, en and ar).
 *
 * Only where an address is first taken in: registration, the operator
 * invitation, the team invitation and the console bootstrap. Sign-in, forgot
 * and reset only look up a login that already exists, and normalise() makes
 * the lookup find it however its address is written, among the spellings the
 * `email` rule on those routes accepts (it refuses a trailing ASCII root label,
 * so `ops@example.com.` must be typed without the dot there). Refusing there locked
 * people out of addresses that do receive mail (verifier of round eight, on
 * 9bbf092, where this required UTS #46 to accept the domain and so refused
 * `x@mail.ab--cd.example.com`). A domain UTS #46 refuses is not refused
 * here for that reason: it is refused only when its typed form already holds
 * a separator or a control character (U+1680, U+2028, U+2029, which the
 * email rules let through; the C1 controls, which they refuse first). A login stored before this rule under an address that would not be
 * delivered where it is written is refused nowhere: it simply gets no mail,
 * as before this round.
 *
 * A value that is not a string is left to the rules beside it.
 */
final class ALoginAddressThatSplitsWhereWritten implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! LoginAddress::splitsWhereWritten($value)) {
            $fail('validation.requests.login_address.not_where_written')->translate();
        }
    }
}
