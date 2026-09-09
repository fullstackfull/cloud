<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Application\Actions\AssessProvider;
use Lynomia\Modules\Providers\Application\Actions\DisableProvider;
use Lynomia\Modules\Providers\Application\Actions\EnableProvider;
use Lynomia\Modules\Providers\Application\Actions\RegisterProvider;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Exceptions\ProviderRefused;
use Lynomia\Modules\Providers\Http\Requests\DisableProviderRequest;
use Lynomia\Modules\Providers\Http\Requests\RegisterProviderRequest;
use Lynomia\Modules\Providers\Http\Resources\ProviderResource;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Symfony\Component\HttpFoundation\Response;

/**
 * The accounts Lynomia holds with other people, and the two decisions about
 * each one.
 *
 * Registering says a provider is meant to exist. Enabling says work may be
 * routed to it. They are separate endpoints behind the same permission because
 * they are separate decisions taken at different times — usually weeks apart,
 * with a credential, a licence and a successful test in between.
 *
 * Refusals are 409, matching the machines surface: the request was
 * well-formed, and the provider is not in a state where the thing asked for is
 * allowed. An operator reading a 422 goes and edits their request; one reading
 * a 409 goes and looks at the provider, which is where the answer is.
 */
final class ProviderController
{
    use ListsAcrossTenants;

    public function index(Request $request): JsonResponse
    {
        $providers = ProviderInstance::query()
            ->with(['credential', 'licence', 'server', 'capabilities'])
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')->value()))
            ->when($request->filled('environment'), fn ($query) => $query->where('environment', $request->string('environment')->value()))
            ->when($request->filled('state'), fn ($query) => $query->where('state', $request->string('state')->value()))
            /*
             * Blocked first, then draft, then the working ones. The control
             * centre exists to show what is stopping the platform selling
             * something, so the default order is "what needs a person".
             */
            ->orderByRaw("case state when 'blocked' then 0 when 'draft' then 1 when 'ready' then 2 when 'disabled' then 3 else 4 end")
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->paginated($providers, fn (ProviderInstance $provider): array => (new ProviderResource($provider))->toArray($request));
    }

    public function show(Request $request, string $provider): JsonResponse
    {
        $found = ProviderInstance::query()
            ->with(['credential', 'licence', 'server', 'capabilities'])
            ->findOrFail($provider);

        return response()->json(['data' => (new ProviderResource($found))->toArray($request)]);
    }

    public function store(RegisterProviderRequest $request, RegisterProvider $register): JsonResponse
    {
        $server = $request->filled('managed_server_id')
            ? ManagedServer::query()->findOrFail($request->string('managed_server_id')->value())
            : null;

        try {
            $provider = $register->execute(
                name: $request->string('name')->value(),
                driver: $request->string('driver')->value(),
                category: ProviderCategory::from($request->string('category')->value()),
                environment: DeploymentEnvironment::from($request->string('environment')->value()),
                operator: $request->user(),
                endpoint: $request->input('endpoint'),
                server: $server,
                notes: $request->input('notes'),
            );
        } catch (ProviderRefused $refusal) {
            return $this->refused($refusal);
        }

        $provider->load(['credential', 'licence', 'server', 'capabilities']);

        return response()->json(
            ['data' => (new ProviderResource($provider))->toArray($request)],
            Response::HTTP_CREATED,
        );
    }

    public function enable(Request $request, string $provider, EnableProvider $enable): JsonResponse
    {
        $found = ProviderInstance::query()->findOrFail($provider);

        try {
            $enabled = $enable->execute($found, $request->user());
        } catch (ProviderRefused $refusal) {
            return $this->refused($refusal);
        }

        $enabled->load(['credential', 'licence', 'server', 'capabilities']);

        return response()->json(['data' => (new ProviderResource($enabled))->toArray($request)]);
    }

    public function disable(DisableProviderRequest $request, string $provider, DisableProvider $disable): JsonResponse
    {
        $found = ProviderInstance::query()->findOrFail($provider);

        try {
            $disabled = $disable->execute($found, $request->user(), $request->string('reason')->value());
        } catch (ProviderRefused $refusal) {
            return $this->refused($refusal);
        }

        $disabled->load(['credential', 'licence', 'server', 'capabilities']);

        return response()->json(['data' => (new ProviderResource($disabled))->toArray($request)]);
    }

    /**
     * Recompute what is blocking this provider, now.
     *
     * The stored readiness is written whenever something that feeds it changes,
     * and this is the endpoint for the case that is not covered by that: a
     * licence that expired by the calendar rather than by an edit. Nothing here
     * contacts the provider — that is a connection test, and it is a different
     * endpoint because it costs the other end something.
     */
    public function assess(Request $request, string $provider, AssessProvider $assess): JsonResponse
    {
        $found = ProviderInstance::query()
            ->with(['credential', 'licence', 'server', 'capabilities'])
            ->findOrFail($provider);

        $assess->execute($found);

        return response()->json(['data' => (new ProviderResource($found))->toArray($request)]);
    }

    private function refused(ProviderRefused $refusal): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'provider_refused', 'message' => $refusal->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}
