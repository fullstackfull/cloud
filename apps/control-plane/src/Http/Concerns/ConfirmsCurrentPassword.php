<?php

declare(strict_types=1);

namespace Lynomia\Http\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Lynomia\Modules\Identity\Application\Actions\RecordLoginActivity;
use Lynomia\Modules\Identity\Domain\Enums\LoginOutcome;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Re-confirmation of the account password inside an already authenticated
 * session, for the operations that must not be reachable with a stolen session
 * alone: disabling the second factor, changing the password, minting an API
 * token that outlives the session.
 *
 * It lives at the application's own HTTP layer rather than inside Identity
 * because three modules need it, and a module reaching into another module's
 * controllers to get it is a boundary violation that the architecture suite
 * catches - correctly. Something used by everyone is not Identity's private
 * business; it is plumbing.
 *
 * Three things happen on a failure, and all three are needed:
 *
 *   - the attempt counts towards the same lockout as a failed sign-in, so the
 *     endpoint tires;
 *   - the check refuses outright while the account is locked, so the lockout
 *     cannot be waited out by hammering a different endpoint;
 *   - the attempt is written to the login history, so the customer can see it.
 *
 * The third is not decoration. Someone guessing the password here is already
 * inside the session, which is a worse position than someone guessing at the
 * sign-in form, and it is the one shape of attack that leaves no trace anywhere
 * else: no failed sign-in, no new session row, no notification. If the security
 * page does not show it, nothing does.
 */
trait ConfirmsCurrentPassword
{
    protected function confirmCurrentPassword(Request $request, User $user, string $password): void
    {
        if ($user->isLocked()) {
            $this->recordConfirmationFailure($request, $user, LoginOutcome::Locked);

            throw ValidationException::withMessages([
                'current_password' => 'Too many failed attempts. Try again later.',
            ]);
        }

        if (! Hash::check($password, $user->password)) {
            $user->registerFailedLogin();
            $this->recordConfirmationFailure($request, $user, LoginOutcome::FailedPasswordConfirmation);

            throw ValidationException::withMessages([
                'current_password' => 'That password is incorrect.',
            ]);
        }

        // A correct confirmation clears the counter, exactly as a successful
        // sign-in does, so that occasional mistakes never accumulate into a
        // lockout.
        if ($user->failed_login_attempts > 0) {
            $user->forceFill(['failed_login_attempts' => 0])->save();
        }
    }

    private function recordConfirmationFailure(Request $request, User $user, LoginOutcome $outcome): void
    {
        app(RecordLoginActivity::class)->execute(
            outcome: $outcome,
            request: $request,
            user: $user,
            emailAttempted: $user->email,
        );
    }
}
