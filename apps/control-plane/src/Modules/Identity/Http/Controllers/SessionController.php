<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Controllers;

use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Http\Responses\ApiError;
use Lynomia\Http\Responses\ErrorCatalogue;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

final class SessionController
{
    /**
     * Lists the caller's active sessions so they can spot one they do not
     * recognise. Only the caller's own sessions are ever visible.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $currentId = $request->hasSession() ? $request->session()->getId() : null;

        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->limit(100)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return response()->json([
            'data' => $sessions->map(static fn (object $session): array => [
                // The raw session id is a bearer credential; expose a stable
                // digest so the client can address a session for revocation
                // without ever holding something it could replay.
                'id' => hash('sha256', (string) $session->id),
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_active_at' => date('c', (int) $session->last_activity),
                'is_current' => $currentId !== null && hash_equals((string) $session->id, $currentId),
            ])->all(),
        ]);
    }

    public function destroy(Request $request, string $session): JsonResponse
    {
        $user = $this->currentUser($request);

        // The client addresses sessions by digest, so match on the digest.
        $target = DB::table('sessions')
            ->where('user_id', $user->id)
            ->get(['id'])
            ->first(static fn (object $row): bool => hash_equals(hash('sha256', (string) $row->id), $session));

        if ($target === null) {
            return ApiError::make(
                'resource.not_found',
                ErrorCatalogue::message('resource.not_found', [], 'The requested resource does not exist.'),
                404,
            )->toResponse($request);
        }

        DB::table('sessions')->where('id', $target->id)->delete();
        $this->evictRememberedDevices($request, $user);

        return response()->json(status: 204);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $currentId = $request->hasSession() ? $request->session()->getId() : null;

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($currentId !== null, fn ($query) => $query->where('id', '!=', $currentId))
            ->delete();

        $this->evictRememberedDevices($request, $user);

        return response()->json(status: 204);
    }

    public function loginActivity(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $activity = $user->loginActivities()
            ->latest('created_at')
            ->limit(50)
            ->get(['id', 'outcome', 'ip_address', 'user_agent', 'country', 'created_at']);

        return response()->json([
            'data' => $activity->map(static fn ($row): array => [
                'id' => $row->id,
                'outcome' => $row->outcome->value,
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'country' => $row->country,
                'occurred_at' => $row->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Deleting session rows is not enough to get a device out.
     *
     * A remember-me cookie is a standalone credential — `id|remember_token|
     * password-hash` — that SessionGuard re-authenticates from and mints a
     * brand-new session for on the very next request. It is not a session row,
     * so it cannot be listed or addressed individually, and revocation that
     * leaves `remember_token` alone leaves a stolen cookie fully working.
     * Rotating the token is what actually evicts those devices.
     *
     * The device the customer is revoking *from* keeps its persistent login:
     * if it presented a recaller, it is re-issued one under the new token,
     * exactly as the framework's own logoutOtherDevices does.
     */
    private function evictRememberedDevices(Request $request, User $user): void
    {
        $guard = Auth::guard('web');
        $keepThisDevice = $guard instanceof SessionGuard
            && $request->cookies->has($guard->getRecallerName());

        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if ($keepThisDevice) {
            $guard->login($user, remember: true);
        }
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 401);

        return $user;
    }
}
