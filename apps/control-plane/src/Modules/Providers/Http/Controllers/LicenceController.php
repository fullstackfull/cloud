<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Application\Actions\AttachLicence;
use Lynomia\Modules\Providers\Application\Actions\InvalidateLicence;
use Lynomia\Modules\Providers\Application\Actions\RecordLicence;
use Lynomia\Modules\Providers\Application\Actions\RefreshLicenceStates;
use Lynomia\Modules\Providers\Application\Actions\RenewLicence;
use Lynomia\Modules\Providers\Domain\Exceptions\LicenceRefused;
use Lynomia\Modules\Providers\Http\Requests\AttachLicenceRequest;
use Lynomia\Modules\Providers\Http\Requests\InvalidateLicenceRequest;
use Lynomia\Modules\Providers\Http\Requests\RecordLicenceRequest;
use Lynomia\Modules\Providers\Http\Requests\RenewLicenceRequest;
use Lynomia\Modules\Providers\Http\Resources\LicenceResource;
use Lynomia\Modules\Providers\Http\Resources\ProviderResource;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\Licence;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Symfony\Component\HttpFoundation\Response;

/**
 * The licences Lynomia holds, and when each stops being true.
 */
final class LicenceController
{
    use ListsAcrossTenants;

    private const array WITH = ['server', 'credential'];

    public function index(Request $request): JsonResponse
    {
        $licences = Licence::query()
            ->with(self::WITH)
            ->withCount('providerInstances')
            ->when($request->filled('environment'), fn ($query) => $query->where('environment', $request->string('environment')->value()))
            ->when($request->filled('state'), fn ($query) => $query->where('state', $request->string('state')->value()))
            // What needs a person first: expired and invalid, then expiring,
            // then everything else by when it lapses.
            ->orderByRaw("case state when 'expired' then 0 when 'invalid' then 1 when 'missing' then 2 when 'expiring' then 3 when 'pending' then 4 else 5 end")
            ->orderBy('expires_on')
            ->paginate($this->perPage($request));

        return $this->paginated($licences, fn (Licence $licence): array => (new LicenceResource($licence))->toArray($request));
    }

    public function show(Request $request, string $licence): JsonResponse
    {
        $found = Licence::query()->with(self::WITH)->withCount('providerInstances')->findOrFail($licence);

        return response()->json(['data' => (new LicenceResource($found))->toArray($request)]);
    }

    public function store(RecordLicenceRequest $request, RecordLicence $record): JsonResponse
    {
        $date = fn (string $field): ?CarbonImmutable => $request->filled($field)
            ? CarbonImmutable::parse($request->string($field)->value())->startOfDay()
            : null;

        try {
            $licence = $record->execute(
                product: $request->string('product')->value(),
                environment: DeploymentEnvironment::from($request->string('environment')->value()),
                operator: $request->user(),
                licenceType: $request->input('licence_type'),
                server: $request->filled('managed_server_id') ? ManagedServer::query()->findOrFail($request->string('managed_server_id')->value()) : null,
                credential: $request->filled('credential_id') ? CredentialReference::query()->findOrFail($request->string('credential_id')->value()) : null,
                startsOn: $date('starts_on'),
                expiresOn: $date('expires_on'),
                renewsOn: $date('renews_on'),
                seats: $request->filled('seats') ? $request->integer('seats') : null,
                externalReference: $request->input('external_reference'),
                notes: $request->input('notes'),
            );
        } catch (LicenceRefused $refusal) {
            return $this->refused($refusal);
        }

        $licence->load(self::WITH)->loadCount('providerInstances');

        return response()->json(['data' => (new LicenceResource($licence))->toArray($request)], Response::HTTP_CREATED);
    }

    public function renew(RenewLicenceRequest $request, string $licence, RenewLicence $renew): JsonResponse
    {
        $found = Licence::query()->findOrFail($licence);

        try {
            $renewed = $renew->execute(
                $found,
                $request->user(),
                CarbonImmutable::parse($request->string('expires_on')->value())->startOfDay(),
                $request->filled('renews_on') ? CarbonImmutable::parse($request->string('renews_on')->value())->startOfDay() : null,
                $request->input('external_reference'),
            );
        } catch (LicenceRefused $refusal) {
            return $this->refused($refusal);
        }

        $renewed->load(self::WITH)->loadCount('providerInstances');

        return response()->json(['data' => (new LicenceResource($renewed))->toArray($request)]);
    }

    public function invalidate(InvalidateLicenceRequest $request, string $licence, InvalidateLicence $invalidate): JsonResponse
    {
        $found = Licence::query()->findOrFail($licence);

        try {
            $invalidated = $invalidate->execute($found, $request->user(), $request->string('reason')->value());
        } catch (LicenceRefused $refusal) {
            return $this->refused($refusal);
        }

        $invalidated->load(self::WITH)->loadCount('providerInstances');

        return response()->json(['data' => (new LicenceResource($invalidated))->toArray($request)]);
    }

    /**
     * Let the calendar move states now rather than at the nightly sweep.
     *
     * The same action the scheduler runs, so what an operator triggers is
     * exactly what runs unattended.
     */
    public function refresh(RefreshLicenceStates $refresh): JsonResponse
    {
        return response()->json(['data' => $refresh->execute()]);
    }

    public function attachToProvider(AttachLicenceRequest $request, string $provider, AttachLicence $attach): JsonResponse
    {
        $found = ProviderInstance::query()->findOrFail($provider);
        $licence = Licence::query()->findOrFail($request->string('licence_id')->value());

        try {
            $attached = $attach->toProvider($found, $licence, $request->user());
        } catch (LicenceRefused $refusal) {
            return $this->refused($refusal);
        }

        return response()->json(['data' => (new ProviderResource($attached->load(['credential', 'licence', 'server', 'capabilities'])))->toArray($request)]);
    }

    public function detachFromProvider(Request $request, string $provider, AttachLicence $attach): JsonResponse
    {
        $found = ProviderInstance::query()->with('licence')->findOrFail($provider);

        $detached = $attach->fromProvider($found, $request->user());

        return response()->json(['data' => (new ProviderResource($detached->load(['credential', 'licence', 'server', 'capabilities'])))->toArray($request)]);
    }

    private function refused(LicenceRefused $refusal): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'licence_refused', 'message' => $refusal->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}
