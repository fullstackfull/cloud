<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Domain\Enums\LoginOutcome;
use Lynomia\Modules\Identity\Domain\Exceptions\AccountLockedException;
use Lynomia\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use Lynomia\Modules\Identity\Domain\Exceptions\TwoFactorRequiredException;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Verifies a password and either signs the user in or demands a second factor.
 *
 * Deliberate properties:
 *
 * - The response is identical for an unknown address and a wrong password, and
 *   a hash is computed in both cases, so neither the message nor the timing
 *   reveals whether an account exists.
 * - A correct password on a two-factor account produces a challenge token, not
 *   a session. Possession of the password alone never yields authenticated
 *   state, not even for one request.
 * - The challenge token is single-use, short-lived, and stored server-side
 *   keyed by a random value, so it cannot be forged or replayed.
 */
final readonly class AttemptLogin
{
    public const int CHALLENGE_TTL_SECONDS = 300;

    private const string CHALLENGE_CACHE_PREFIX = 'auth:2fa-challenge:';

    public function __construct(
        private RecordLoginActivity $recordActivity,
    ) {}

    /**
     * @return User the authenticated user, when no second factor is required
     *
     * @throws InvalidCredentialsException
     * @throws AccountLockedException
     * @throws TwoFactorRequiredException
     */
    public function execute(string $email, string $password, Request $request): User
    {
        $email = strtolower(trim($email));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // Spend the same work as a real verification so that response time
            // does not distinguish "no such account" from "wrong password".
            Hash::make($password);

            $this->recordActivity->execute(LoginOutcome::FailedCredentials, $request, emailAttempted: $email);

            throw InvalidCredentialsException::make();
        }

        if ($user->isLocked()) {
            $this->recordActivity->execute(LoginOutcome::Locked, $request, $user, $email);

            /*
             * Only an account that exists can be locked, so answering 423 to
             * anyone who asks turns the lockout into a membership oracle: five
             * requests - exactly the throttle budget - classify any address
             * with certainty. Everything above this line goes to real trouble
             * to make an unknown address indistinguishable from a wrong
             * password, and this would give it all back.
             *
             * The detail is worth keeping for the person who owns the account,
             * though: "locked until 14:05" is a far better answer than "wrong
             * password" when the password was right. So the password decides.
             * Someone who can present it already knows the account exists.
             */
            if (Hash::check($password, $user->password)) {
                throw AccountLockedException::until($user->locked_until);
            }

            throw InvalidCredentialsException::make();
        }

        if (! Hash::check($password, $user->password)) {
            $locked = $user->registerFailedLogin();

            $this->recordActivity->execute(
                $locked ? LoginOutcome::Locked : LoginOutcome::FailedCredentials,
                $request,
                $user,
                $email,
            );

            if ($locked) {
                throw AccountLockedException::until($user->locked_until);
            }

            throw InvalidCredentialsException::make();
        }

        if ($user->hasTwoFactorEnabled()) {
            throw TwoFactorRequiredException::withChallenge(
                $this->issueChallenge($user),
                self::CHALLENGE_TTL_SECONDS,
            );
        }

        $user->registerSuccessfulLogin($request->ip());
        $this->recordActivity->execute(LoginOutcome::Success, $request, $user, $email);

        return $user;
    }

    /**
     * Redeem a challenge token, returning the user it was issued for.
     *
     * The token is removed on read, so a captured token cannot be replayed.
     */
    public function redeemChallenge(string $token): ?User
    {
        $key = self::CHALLENGE_CACHE_PREFIX.hash('sha256', $token);

        $userId = Cache::pull($key);

        if (! is_string($userId)) {
            return null;
        }

        return User::query()->find($userId);
    }

    private function issueChallenge(User $user): string
    {
        $token = Str::random(64);

        // Only the hash is stored, so a cache dump does not yield usable
        // challenge tokens.
        Cache::put(
            self::CHALLENGE_CACHE_PREFIX.hash('sha256', $token),
            $user->id,
            self::CHALLENGE_TTL_SECONDS,
        );

        return $token;
    }
}
