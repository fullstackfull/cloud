<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Providers\Application\Actions\AttachCredential;
use Lynomia\Modules\Providers\Application\Actions\MarkCredentialRotated;
use Lynomia\Modules\Providers\Application\Actions\RecordCredentialReference;
use Lynomia\Modules\Providers\Application\Actions\RevokeCredential;
use Lynomia\Modules\Providers\Domain\Exceptions\CredentialRefused;
use Lynomia\Modules\Providers\Http\Requests\AttachCredentialRequest;
use Lynomia\Modules\Providers\Http\Requests\RecordCredentialRequest;
use Lynomia\Modules\Providers\Http\Requests\RevokeCredentialRequest;
use Lynomia\Modules\Providers\Http\Requests\RotateCredentialRequest;
use Lynomia\Modules\Providers\Http\Resources\CredentialResource;
use Lynomia\Modules\Providers\Http\Resources\ProviderResource;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Symfony\Component\HttpFoundation\Response;

/**
 * The credentials Lynomia holds — as references, never as values.
 *
 * No method here returns a secret, a path into the secret store, or a hint
 * derived from either. That is not a policy applied to responses; it is that
 * nothing this controller touches has the value to give.
 */
final class CredentialController
{
    use ListsAcrossTenants;

    private const array USAGE = ['providerInstances', 'servers'];

    public function index(Request $request): JsonResponse
    {
        $credentials = CredentialReference::query()
            ->withCount(self::USAGE)
            ->when($request->filled('environment'), fn ($query) => $query->where('environment', $request->string('environment')->value()))
            ->when($request->filled('state'), fn ($query) => $query->where('state', $request->string('state')->value()))
            // What needs a person first: missing and invalid, then the rest.
            ->orderByRaw("case state when 'missing' then 0 when 'invalid' then 1 when 'expired' then 2 when 'rotation_due' then 3 else 4 end")
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->paginated($credentials, fn (CredentialReference $credential): array => (new CredentialResource($credential))->toArray($request));
    }

    public function show(Request $request, string $credential): JsonResponse
    {
        $found = CredentialReference::query()->withCount(self::USAGE)->findOrFail($credential);

        return response()->json(['data' => (new CredentialResource($found))->toArray($request)]);
    }

    public function store(RecordCredentialRequest $request, RecordCredentialReference $record): JsonResponse
    {
        try {
            $credential = $record->execute(
                name: $request->string('name')->value(),
                purpose: $request->string('purpose')->value(),
                environment: DeploymentEnvironment::from($request->string('environment')->value()),
                backend: $request->string('backend')->value(),
                reference: $request->string('backend_reference')->value(),
                operator: $request->user(),
                maskedHint: $request->input('masked_hint'),
                rotatesAt: $request->filled('rotates_at') ? CarbonImmutable::parse($request->string('rotates_at')->value()) : null,
                notes: $request->input('notes'),
            );
        } catch (CredentialRefused $refusal) {
            return $this->refused($refusal);
        }

        $credential->loadCount(self::USAGE);

        return response()->json(['data' => (new CredentialResource($credential))->toArray($request)], Response::HTTP_CREATED);
    }

    public function revoke(RevokeCredentialRequest $request, string $credential, RevokeCredential $revoke): JsonResponse
    {
        $found = CredentialReference::query()->findOrFail($credential);

        try {
            $revoked = $revoke->execute($found, $request->user(), $request->string('reason')->value());
        } catch (CredentialRefused $refusal) {
            return $this->refused($refusal);
        }

        $revoked->loadCount(self::USAGE);

        return response()->json(['data' => (new CredentialResource($revoked))->toArray($request)]);
    }

    public function rotated(RotateCredentialRequest $request, string $credential, MarkCredentialRotated $rotate): JsonResponse
    {
        $found = CredentialReference::query()->findOrFail($credential);

        try {
            $rotated = $rotate->execute(
                $found,
                $request->user(),
                $request->filled('rotates_at') ? CarbonImmutable::parse($request->string('rotates_at')->value()) : null,
            );
        } catch (CredentialRefused $refusal) {
            return $this->refused($refusal);
        }

        $rotated->loadCount(self::USAGE);

        return response()->json(['data' => (new CredentialResource($rotated))->toArray($request)]);
    }

    public function attachToProvider(AttachCredentialRequest $request, string $provider, AttachCredential $attach): JsonResponse
    {
        $found = ProviderInstance::query()->findOrFail($provider);
        $credential = CredentialReference::query()->findOrFail($request->string('credential_id')->value());

        try {
            $attached = $attach->toProvider($found, $credential, $request->user());
        } catch (CredentialRefused $refusal) {
            return $this->refused($refusal);
        }

        return response()->json(['data' => (new ProviderResource($attached->load(['credential', 'licence', 'server', 'capabilities'])))->toArray($request)]);
    }

    public function detachFromProvider(Request $request, string $provider, AttachCredential $attach): JsonResponse
    {
        $found = ProviderInstance::query()->with('credential')->findOrFail($provider);

        $detached = $attach->fromProvider($found, $request->user());

        return response()->json(['data' => (new ProviderResource($detached->load(['credential', 'licence', 'server', 'capabilities'])))->toArray($request)]);
    }

    private function refused(CredentialRefused $refusal): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'credential_refused', 'message' => $refusal->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}
