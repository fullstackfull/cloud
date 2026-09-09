<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Infrastructure\Application\Actions\ClassifyServer;
use Lynomia\Modules\Infrastructure\Application\Actions\ClearForReimage;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterServer;
use Lynomia\Modules\Infrastructure\Application\Actions\RevokeReimageClearance;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\ClassificationRefused;
use Lynomia\Modules\Infrastructure\Http\Requests\ClassifyServerRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\ClearForReimageRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\RegisterServerRequest;
use Lynomia\Modules\Infrastructure\Http\Resources\ServerResource;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Symfony\Component\HttpFoundation\Response;

/**
 * The machines, and the small number of things an operator may do to one.
 *
 * ---------------------------------------------------------------------------
 * What this controller is careful about
 * ---------------------------------------------------------------------------
 *
 * Every write here is a call to an action, never a model save. The actions hold
 * the locking, the audit entry and the safety rules, and a controller that
 * wrote a column directly would be a second path to the same change with none
 * of them — which is how a classification ends up altered with nothing
 * recording who did it.
 *
 * The refusals come back as 409 rather than 422. A refusal is not "your input
 * was malformed"; it is "the machine is not in a state where that is allowed",
 * and the distinction matters to an operator deciding whether to fix their
 * request or go and change something.
 */
final class ServerController
{
    use ListsAcrossTenants;

    public function index(Request $request): JsonResponse
    {
        $servers = ManagedServer::query()
            ->with('credential')
            ->when($request->filled('environment'), fn ($query) => $query->where('environment', $request->string('environment')->value()))
            ->when($request->filled('state'), fn ($query) => $query->where('state', $request->string('state')->value()))
            ->when($request->filled('safety_class'), fn ($query) => $query->where('safety_class', $request->string('safety_class')->value()))
            /*
             * The machines somebody has to decide about, first. A screen opened
             * during onboarding should lead with what is unclassified and
             * unreachable rather than with what is already working.
             */
            ->orderByRaw("case safety_class when 'do_not_touch' then 0 when 'discovery_only' then 1 else 2 end")
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->paginated($servers, fn (ManagedServer $server): array => (new ServerResource($server))->toArray($request));
    }

    public function show(Request $request, string $server): JsonResponse
    {
        $found = ManagedServer::query()->with('credential')->findOrFail($server);

        return response()->json(['data' => (new ServerResource($found))->toArray($request)]);
    }

    public function store(RegisterServerRequest $request, RegisterServer $register): JsonResponse
    {
        $server = $register->execute($request->toRegistration());

        return response()->json(
            ['data' => (new ServerResource($server))->toArray($request)],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Raise or lower what may be done to a machine.
     *
     * Reaching the destructive rung takes a second permission on the route as
     * well as this one, so an operator who may reclassify machines day to day
     * still cannot make one wipeable.
     */
    public function classify(ClassifyServerRequest $request, string $server, ClassifyServer $classify): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);
        $to = SafetyClass::from($request->string('safety_class')->value());

        if ($to->isDestructive() && $request->user()?->cannot('safety.allow_reimage')) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Making a machine reimageable needs the reimage permission, not only the safety one.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $updated = $classify->execute(
                $found,
                $to,
                $request->user(),
                $request->string('reason')->value(),
                $request->input('confirm_name'),
            );
        } catch (ClassificationRefused $refused) {
            return $this->refused($refused->getMessage());
        }

        return response()->json(['data' => (new ServerResource($updated))->toArray($request)]);
    }

    public function clearForReimage(ClearForReimageRequest $request, string $server, ClearForReimage $clear): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);

        try {
            $updated = $clear->execute(
                $found,
                $request->user(),
                $request->string('reason')->value(),
                $request->string('confirm_name')->value(),
            );
        } catch (ClassificationRefused $refused) {
            return $this->refused($refused->getMessage());
        }

        return response()->json(['data' => (new ServerResource($updated))->toArray($request)]);
    }

    public function revokeReimageClearance(Request $request, string $server, RevokeReimageClearance $revoke): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);

        $updated = $revoke->execute($found, $request->user());

        return response()->json(['data' => (new ServerResource($updated))->toArray($request)]);
    }

    private function refused(string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'refused', 'message' => $message],
        ], Response::HTTP_CONFLICT);
    }
}
