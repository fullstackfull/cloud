<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Lynomia\Modules\Identity\Application\Actions\AttemptLogin;
use Lynomia\Modules\Identity\Application\Actions\ManageTwoFactor;
use Lynomia\Modules\Identity\Application\Actions\RecordLoginActivity;
use Lynomia\Modules\Identity\Domain\Enums\LoginOutcome;
use Lynomia\Modules\Identity\Http\Requests\LoginRequest;
use Lynomia\Modules\Identity\Http\Resources\UserResource;

final class LoginController
{
    public function store(LoginRequest $request, AttemptLogin $attempt): JsonResponse
    {
        $user = $attempt->execute(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            $request,
        );

        // Explicitly the web (session) guard. Relying on the default guard
        // would break the moment another middleware calls Auth::shouldUse(),
        // and the portals always authenticate against the session guard.
        Auth::guard('web')->login($user, remember: (bool) $request->boolean('remember'));

        // A fresh session id after privilege change defeats session fixation.
        $request->session()->regenerate();

        return response()->json([
            'data' => new UserResource($user->load('customers')),
        ]);
    }

    /**
     * Completes a sign-in that stopped at the second factor.
     *
     * The challenge token proves the password stage was passed; it is consumed
     * on redemption, so it cannot be replayed.
     */
    public function twoFactorChallenge(
        Request $request,
        AttemptLogin $attempt,
        ManageTwoFactor $twoFactor,
        RecordLoginActivity $recordActivity,
    ): JsonResponse {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $user = $attempt->redeemChallenge($validated['challenge_token']);

        if ($user === null) {
            throw ValidationException::withMessages([
                'challenge_token' => __('validation.requests.auth.challenge_expired'),
            ]);
        }

        if (! $twoFactor->verifyChallenge($user, $validated['code'])) {
            $recordActivity->execute(LoginOutcome::FailedTwoFactor, $request, $user, $user->email);

            // Counts towards lockout: without this, the second factor would be
            // brute-forceable once the password is known.
            $user->registerFailedLogin();

            throw ValidationException::withMessages([
                'code' => __('validation.requests.auth.code_invalid'),
            ]);
        }

        $user->registerSuccessfulLogin($request->ip());
        $recordActivity->execute(LoginOutcome::Success, $request, $user, $user->email);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'data' => new UserResource($user->load('customers')),
        ]);
    }

    public function destroy(Request $request, RecordLoginActivity $recordActivity): JsonResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $recordActivity->execute(LoginOutcome::LoggedOut, $request, $user, $user->email);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Guards cache the user they resolved. Sanctum's guard in particular
        // holds its own reference, so clearing only the web guard would leave
        // the request — and any middleware still to run — believing the user is
        // signed in.
        Auth::forgetGuards();

        // A token-authenticated caller has no session to invalidate; revoke the
        // token they presented instead, so "log out" means the same thing for
        // both kinds of client.
        $token = $user?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(status: 204);
    }
}
