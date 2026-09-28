<?php

declare(strict_types=1);

namespace Lynomia\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;

/**
 * A login address that is delivered where it was written
 * (LoginAddress::deliversAsWritten()).
 *
 * `email:rfc,strict` accepts a full-width or small commercial at (U+FF20,
 * U+FE6B) and Unicode spaces in the domain. UTS #46, which is how the stored
 * spelling of a domain is made, maps the first to `@` and the others to a
 * space: `ops@company.test＠evil.test` would have been stored as an address
 * whose last `@` names evil.test, and the mailer refuses to address either
 * (verifier of round eight, on 2698318). This refuses them, with one
 * sentence (validation.requests.login_address.undeliverable, en and ar).
 *
 * One rule for every route that takes a login address: registration,
 * sign-in, forgot and reset, the operator invitation, the team invitation and
 * the console bootstrap. A value that is not a string is left to the rules
 * beside it.
 */
final class ALoginAddressThatDelivers implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! LoginAddress::deliversAsWritten($value)) {
            $fail('validation.requests.login_address.undeliverable')->translate();
        }
    }
}
