<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Lynomia\Modules\Identity\Http\Controllers\Concerns\ConfirmsCurrentPassword;
use Lynomia\Modules\Identity\Http\Resources\UserResource;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

final class ProfileController
{
    use ConfirmsCurrentPassword;

    public function show(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return response()->json([
            'data' => new UserResource($user->load('customers')),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'locale' => ['sometimes', 'string', Rule::in(config('app.supported_locales', ['en']))],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/\A\+?[0-9 ()-]{6,32}\z/'],
        ]);

        // The email address is deliberately not updatable here: changing it
        // requires re-verification and a separate, audited flow.
        $user->fill($validated)->save();

        return response()->json(['data' => new UserResource($user->fresh())]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $this->confirmCurrentPassword($request, $user, $validated['current_password']);

        $user->forceFill([
            'password' => $validated['password'],
            'password_changed_at' => now(),
        ])->save();

        /*
         * A password change invalidates every other session and every API
         * token. Otherwise a customer who changes their password because they
         * suspect compromise leaves the attacker's session running.
         */
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($currentSessionId !== null, fn ($query) => $query->where('id', '!=', $currentSessionId))
            ->delete();

        $user->tokens()->delete();

        return response()->json(status: 204);
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 401);

        return $user;
    }
}
