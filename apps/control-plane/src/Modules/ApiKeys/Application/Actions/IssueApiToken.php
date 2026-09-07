<?php

declare(strict_types=1);

namespace Lynomia\Modules\ApiKeys\Application\Actions;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Lynomia\Modules\ApiKeys\Domain\Exceptions\RateLimitAboveCeilingException;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Mints one API token, bound to one customer account, and returns the plaintext
 * for the only time it will ever exist.
 *
 * The binding is written in the same INSERT as the token digest, deliberately.
 * Sanctum's `createToken()` followed by an update that sets `customer_id` would
 * leave a live, *unbound* token in the table for as long as that second
 * statement takes — and an unbound token is one whose acting customer is chosen
 * by an `X-Lynomia-Customer` header, which is precisely the widening this
 * column exists to prevent. The window is small; it is also entirely avoidable.
 *
 * Only the digest is stored. The plaintext exists in this method's local scope
 * and in the response body, and nowhere else: there is no column it could be
 * read back from, which is what makes "shown once" true rather than a promise
 * the UI keeps.
 */
final class IssueApiToken
{
    /**
     * Issues a token for `$user` that acts for `$customer`.
     *
     * @param  list<string>|null  $allowedIpRanges  CIDR blocks or bare addresses; null or [] means anywhere
     *
     * @throws RateLimitAboveCeilingException
     */
    public function execute(
        User $user,
        Customer $customer,
        string $name,
        ?DateTimeInterface $expiresAt = null,
        ?array $allowedIpRanges = null,
        ?int $rateLimitPerMinute = null,
    ): NewAccessToken {
        $rateLimitPerMinute = $this->boundedRateLimit($rateLimitPerMinute);

        $plainText = $user->generateTokenString();

        /*
         * A transaction around a single insert buys nothing on its own; it is
         * here so that a listener or an audit write added to this action later
         * commits with the token rather than after it.
         */
        $token = DB::transaction(static function () use (
            $user,
            $customer,
            $name,
            $plainText,
            $expiresAt,
            $allowedIpRanges,
            $rateLimitPerMinute,
        ): PersonalAccessToken {
            /*
             * `make()` sets the polymorphic owner, `forceFill()` writes the
             * rest. Sanctum's base model declares a $fillable that lists four
             * columns, so a plain `create()` with this module's additions
             * raises a MassAssignmentException — and the fix belongs in the
             * model's fillable list rather than here, but that file is owned
             * elsewhere. Nothing here comes from request input unchecked: every
             * value is a validated argument to this method.
             */
            /** @var PersonalAccessToken $created */
            $created = $user->tokens()->make();

            $created->forceFill([
                'customer_id' => $customer->getKey(),
                'name' => $name,
                'token' => hash('sha256', $plainText),

                /*
                 * Full abilities, and no way to ask for anything narrower.
                 * Nothing in this application calls `tokenCan()`, so an
                 * `abilities` list accepted from a request would be a control
                 * the API advertises and does not apply — a customer would
                 * believe a token was read-only while it placed orders. When
                 * the endpoints check abilities, this becomes a request field.
                 */
                'abilities' => ['*'],
                'expires_at' => $expiresAt,
                'allowed_ip_ranges' => $allowedIpRanges === [] ? null : $allowedIpRanges,
                'rate_limit_per_minute' => $rateLimitPerMinute,
            ])->save();

            return $created;
        });

        return new NewAccessToken($token, $token->getKey().'|'.$plainText);
    }

    /**
     * The highest ceiling a customer may give one of their own tokens.
     *
     * `rate_limit_per_minute` overrides the tier default in the `api` limiter,
     * so an unbounded value is a self-service exemption from throttling.
     */
    public static function ceilingPerMinute(): int
    {
        return max(1, (int) config('security.rate_limits.api_token.attempts', 120));
    }

    /**
     * @throws RateLimitAboveCeilingException
     */
    private function boundedRateLimit(?int $requested): ?int
    {
        if ($requested === null) {
            return null;
        }

        $ceiling = self::ceilingPerMinute();

        // Refused, not clamped. A page size silently reduced to 100 still
        // answers the caller's question; a throttle silently reduced from the
        // 5000/min an integration asked for leaves that integration failing in
        // production against a limit it was told it had.
        if ($requested < 1 || $requested > $ceiling) {
            throw RateLimitAboveCeilingException::ceiling($requested, $ceiling);
        }

        return $requested;
    }
}
