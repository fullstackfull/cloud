<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Infrastructure\Application\Actions\CancelDeployment;
use Lynomia\Modules\Infrastructure\Application\Actions\RequestDeployment;
use Lynomia\Modules\Infrastructure\Application\Actions\ResolveDeployment;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentKind;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\SafetyRefusal;
use Lynomia\Modules\Infrastructure\Http\Requests\CancelDeploymentRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\RequestDeploymentRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\ResolveDeploymentRequest;
use Lynomia\Modules\Infrastructure\Http\Resources\DeploymentJobResource;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentJob;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Symfony\Component\HttpFoundation\Response;

final class DeploymentJobController
{
    use ListsAcrossTenants;

    public function index(Request $request): JsonResponse
    {
        $jobs = DeploymentJob::query()
            ->with('server')
            ->when($request->filled('state'), fn ($q) => $q->where('state', $request->string('state')->value()))
            ->when($request->filled('server'), fn ($q) => $q->where('managed_server_id', $request->string('server')->value()))
            // The ones waiting for a person first; then newest.
            ->orderByRaw("case state when 'indeterminate' then 0 when 'needs_review' then 1 when 'applying' then 2 when 'verifying' then 2 when 'queued' then 3 else 4 end")
            ->latest('created_at')
            ->paginate($this->perPage($request));

        return $this->paginated($jobs, fn (DeploymentJob $job): array => (new DeploymentJobResource($job))->toArray($request));
    }

    public function show(Request $request, string $deployment): JsonResponse
    {
        $found = DeploymentJob::query()->with('server')->findOrFail($deployment);

        return response()->json(['data' => (new DeploymentJobResource($found))->toArray($request)]);
    }

    public function request(RequestDeploymentRequest $request, string $server, RequestDeployment $start): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);

        try {
            $job = $start->execute($found, DeploymentKind::from($request->string('kind')->value()), $request->user());
        } catch (DeploymentRefused $refusal) {
            return Refusals::deployment($refusal);
        } catch (SafetyRefusal $refusal) {
            return Refusals::safety($refusal);
        }

        return response()->json(
            ['data' => (new DeploymentJobResource($job->fresh(['server'])))->toArray($request)],
            Response::HTTP_ACCEPTED,
        );
    }

    public function resolve(ResolveDeploymentRequest $request, string $deployment, ResolveDeployment $resolve): JsonResponse
    {
        $found = DeploymentJob::query()->findOrFail($deployment);

        try {
            $resolved = $resolve->execute($found, $request->user(), $request->string('outcome')->value() === 'completed', $request->string('reason')->value());
        } catch (DeploymentRefused $refusal) {
            return Refusals::deployment($refusal);
        }

        return response()->json(['data' => (new DeploymentJobResource($resolved->load('server')))->toArray($request)]);
    }

    public function cancel(CancelDeploymentRequest $request, string $deployment, CancelDeployment $cancel): JsonResponse
    {
        $found = DeploymentJob::query()->findOrFail($deployment);

        try {
            $cancelled = $cancel->execute($found, $request->user(), $request->string('reason')->value());
        } catch (DeploymentRefused $refusal) {
            return Refusals::deployment($refusal);
        }

        return response()->json(['data' => (new DeploymentJobResource($cancelled->load('server')))->toArray($request)]);
    }
}
