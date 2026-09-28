<?php

declare(strict_types=1);

namespace Lynomia\Http\Rules;

use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;

/**
 * The one set of rules a login address meets where it is first taken in —
 * registration (RegisterRequest), the operator invitation
 * (InviteOperatorRequest), the team invitation (InviteMemberRequest) and the
 * console bootstrap (BootstrapFirstOperator) — and the one form they are
 * applied to: the address as it will be stored, LoginAddress::normalise().
 *
 * Each of those four normalises the address before it validates it, and
 * validates it with rules(). They used not to agree (B9-1 and X9-2,
 * re-audit after round eight): registration and the bootstrap validated the
 * normalised address, the two invitations the address as typed, each with its
 * own email rule and length. So `ops@EXAMPLE.com。`, valid as typed, was
 * stored as an address nothing could send to, and an address of 130 `İ`
 * (U+0130) — 142 characters as typed, 272 once lowercased — passed the
 * invitations' length rule and failed the column's, a 500. (That address is
 * now refused by `email:rfc,strict` too, for a local part longer than 64
 * octets, which the invitations' `email` and `email:rfc` let through.)
 *
 * The length is MAX_LENGTH, in characters (Laravel's `max` counts them as
 * PostgreSQL's varchar does): the longest address RFC 5321 lets a mail
 * system carry. Every column an address taken in here is stored in —
 * users.email, customer_invitations.email, customers.billing_email and
 * password_reset_tokens.email — is varchar(255);
 * AnAddressThatGrowsWhenNormalisedIsRefusedTest reads their lengths from the
 * schema and goes red if one is shorter.
 *
 * ALoginAddressThatSplitsWhereWritten reads the domain's compatibility form,
 * which normalise() leaves as typed wherever that rule refuses it. Measured
 * for every code point in five contexts (`a` c `.gr`, `ΑΛ` c `Σ.gr`,
 * `x.` c `Σ`, c `ς.gr` and `a.gr` c; 5,560,155 domains), it answers the same
 * for the stored address as for the address as typed in every one.
 */
final class LoginAddressAtIntake
{
    public const int MAX_LENGTH = 254;

    /**
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'email:rfc,strict', 'max:'.self::MAX_LENGTH, new ALoginAddressThatSplitsWhereWritten];
    }

    /**
     * The address as it will be stored, for a request to validate; anything
     * that is not a string is left for rules() to refuse.
     */
    public static function asStored(mixed $value): mixed
    {
        return is_string($value) ? LoginAddress::normalise($value) : $value;
    }
}
