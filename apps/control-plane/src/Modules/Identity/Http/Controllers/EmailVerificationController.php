<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Controllers;

use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Symfony\Component\HttpFoundation\Response;

final class EmailVerificationController
{
    /**
     * Confirms an address from a signed link.
     *
     * The route is not behind an authenticated session on purpose: a customer
     * will often open the link in a different browser from the one they
     * registered in. The signature plus the hash of the current address is what
     * authorises the action, and the hash means a link stops working the moment
     * the address changes.
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::query()->find($id);

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json([
                'error' => [
                    'code' => 'verification.invalid_link',
                    'message' => 'This verification link is not valid.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        if ($user->hasVerifiedEmail()) {
            // Idempotent: a customer clicking the link twice sees success, not
            // an error.
            return response()->json(['data' => ['verified' => true, 'already_verified' => true]]);
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return response()->json(['data' => ['verified' => true, 'already_verified' => false]]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 401);

        if ($user->hasVerifiedEmail()) {
            return response()->json(['data' => ['verified' => true]]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(status: 202);
    }
}
