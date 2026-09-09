<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Infrastructure\Application\Actions\AssignDesiredState;
use Lynomia\Modules\Infrastructure\Application\Actions\ClearDesiredState;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Http\Requests\AssignDesiredStateRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\ClearDesiredStateRequest;
use Lynomia\Modules\Infrastructure\Http\Resources\DesiredStateResource;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Symfony\Component\HttpFoundation\Response;

final class DesiredStateController
{
    public function show(Request $request, string $server): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);
        $state = $found->desiredState()->with('profile')->first();

        return response()->json(['data' => $state === null ? null : (new DesiredStateResource($state))->toArray($request)]);
    }

    public function assign(AssignDesiredStateRequest $request, string $server, AssignDesiredState $assign): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);

        try {
            $state = $assign->execute(
                $found,
                $request->string('profile')->value(),
                (array) $request->input('overrides', []),
                $request->user(),
            );
        } catch (DeploymentRefused $refusal) {
            return Refusals::deployment($refusal);
        }

        return response()->json(['data' => (new DesiredStateResource($state))->toArray($request)]);
    }

    public function clear(ClearDesiredStateRequest $request, string $server, ClearDesiredState $clear): Response
    {
        $found = ManagedServer::query()->findOrFail($server);

        try {
            $clear->execute($found, $request->user(), $request->string('reason')->value());
        } catch (DeploymentRefused $refusal) {
            return Refusals::deployment($refusal);
        }

        return response()->noContent();
    }
}
