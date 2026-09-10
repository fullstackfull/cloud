<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Controllers;

use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Responses\ApiError;
use Lynomia\Http\Responses\ErrorCatalogue;
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
    public function verify(Request $request, string $id, string $hash): JsonResponse|RedirectResponse
    {
        $user = User::query()->find($id);

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            /*
             * Both answers describe the same event; which one is sent depends
             * on who asked. A customer clicking a link in their mail is a
             * browser, and a browser shown raw JSON has been handed the
             * platform's internals and no way forward — which is what used to
             * happen, on the single most important link the platform ever
             * sends. An API client that asked for JSON still gets JSON.
             */
            return $this->wantsJson($request)
                ? ApiError::make(
                    'verification.invalid_link',
                    ErrorCatalogue::message('verification.invalid_link', [], 'This verification link is not valid.'),
                    Response::HTTP_FORBIDDEN,
                )->toResponse($request)
                : $this->toPortal('invalid');
        }

        if ($user->hasVerifiedEmail()) {
            // Idempotent: a customer clicking the link twice sees success, not
            // an error.
            return $this->wantsJson($request)
                ? response()->json(['data' => ['verified' => true, 'already_verified' => true]])
                : $this->toPortal('already_verified');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return $this->wantsJson($request)
            ? response()->json(['data' => ['verified' => true, 'already_verified' => false]])
            : $this->toPortal('verified');
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

    /**
     * Whether the caller is a program or a person in a browser.
     *
     * A person's browser sends `Accept: text/html`; the portal's own fetch and
     * every API client send `Accept: application/json`. The default for
     * anything ambiguous is the browser, because the cost of being wrong in
     * that direction is a redirect an API client can follow, and the cost in
     * the other direction is a customer looking at a JSON blob.
     */
    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson();
    }

    /**
     * Hands the browser back to the portal, saying only what happened.
     *
     * The status is the whole payload: no token, no address, no user id. The
     * portal's /verify-email screen turns it into a sentence, offers the
     * resend button when the link did not work, and points at sign-in when it
     * did. Nothing here is trusted by the API afterwards — the address was
     * marked verified server side before this redirect was built, and no
     * screen may claim otherwise on the strength of a query parameter.
     */
    private function toPortal(string $status): RedirectResponse
    {
        $portal = rtrim((string) config('app.frontend_url'), '/');

        return redirect()->to($portal.'/verify-email?status='.$status);
    }
}
