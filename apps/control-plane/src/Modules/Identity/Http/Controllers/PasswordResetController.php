<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Lynomia\Modules\Identity\Application\Actions\RecordLoginActivity;
use Lynomia\Modules\Identity\Domain\Enums\LoginOutcome;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

final class PasswordResetController
{
    public function sendLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        Password::sendResetLink(['email' => strtolower(trim($validated['email']))]);

        /*
         * Always 202, whatever the outcome.
         *
         * Reporting "no account with that address" would turn this endpoint
         * into an account enumeration oracle, which is a materially worse
         * problem than the mild confusion of a customer who mistyped their
         * address and receives no mail.
         */
        return response()->json([
            'data' => ['message' => 'If that address belongs to an account, a reset link has been sent.'],
        ], 202);
    }

    public function reset(Request $request, RecordLoginActivity $recordActivity): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            [
                'email' => strtolower(trim($validated['email'])),
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (User $user, string $password) use ($request, $recordActivity): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'password_changed_at' => now(),
                    // A successful reset clears any lockout: the legitimate
                    // owner has just proved control of the mailbox.
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();

                // Everything else is invalidated: a reset is most often a
                // response to suspected compromise.
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->tokens()->delete();

                $recordActivity->execute(LoginOutcome::PasswordReset, $request, $user, $user->email);

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PasswordReset) {
            return response()->json([
                'error' => [
                    'code' => 'password.reset_failed',
                    'message' => 'This reset link is invalid or has expired.',
                ],
            ], 422);
        }

        return response()->json(status: 204);
    }
}
