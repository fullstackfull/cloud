<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lynomia\Http\Concerns\ConfirmsCurrentPassword;
use Lynomia\Modules\Identity\Application\Actions\ManageTwoFactor;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

final class TwoFactorController
{
    use ConfirmsCurrentPassword;

    public function __construct(
        private readonly ManageTwoFactor $twoFactor,
    ) {}

    /**
     * Issue a secret and enrolment URL. The account is not yet protected: it
     * becomes so only once the customer proves they can generate a valid code,
     * which prevents locking someone out of their own account with a
     * misconfigured authenticator.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $this->requirePasswordConfirmation($request, $user);

        return response()->json(['data' => $this->twoFactor->beginEnrolment($user)]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $recoveryCodes = $this->twoFactor->confirmEnrolment($user, $validated['code']);

        if ($recoveryCodes === null) {
            throw ValidationException::withMessages(['code' => __('validation.requests.auth.code_invalid')]);
        }

        return response()->json([
            'data' => ['recovery_codes' => $recoveryCodes],
            'meta' => [
                // Shown exactly once: they are stored hashed, so the platform
                // cannot display them again.
                'shown_once' => true,
            ],
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $this->requirePasswordConfirmation($request, $user);

        $this->twoFactor->disable($user);

        return response()->json(status: 204);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $this->requirePasswordConfirmation($request, $user);

        if (! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'code' => __('validation.requests.auth.two_factor_not_enabled'),
            ]);
        }

        return response()->json([
            'data' => ['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($user)],
            'meta' => ['shown_once' => true],
        ]);
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 401);

        return $user;
    }

    /**
     * Changing second-factor settings is a security-sensitive operation, so it
     * requires the current password even inside an authenticated session. A
     * hijacked session must not be enough to disable the second factor.
     */
    private function requirePasswordConfirmation(Request $request, User $user): void
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $this->confirmCurrentPassword($request, $user, $validated['current_password']);
    }
}
