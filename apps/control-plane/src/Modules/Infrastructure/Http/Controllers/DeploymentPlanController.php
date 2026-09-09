<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Infrastructure\Application\Actions\ApproveDeploymentPlan;
use Lynomia\Modules\Infrastructure\Application\Actions\PlanDeployment;
use Lynomia\Modules\Infrastructure\Application\Actions\RevokeDeploymentApproval;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Http\Requests\ApprovePlanRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\RevokeApprovalRequest;
use Lynomia\Modules\Infrastructure\Http\Resources\DeploymentPlanResource;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentPlan;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Symfony\Component\HttpFoundation\Response;

final class DeploymentPlanController
{
    /** The machine's current plan, or null when it has never been planned. */
    public function current(Request $request, string $server): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);
        $plan = $found->plans()->latest('created_at')->first();

        return response()->json(['data' => $plan === null ? null : (new DeploymentPlanResource($plan))->toArray($request)]);
    }

    public function plan(Request $request, string $server, PlanDeployment $plan): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);

        try {
            $computed = $plan->execute($found, $request->user());
        } catch (DeploymentRefused $refusal) {
            return Refusals::deployment($refusal);
        }

        return response()->json(['data' => (new DeploymentPlanResource($computed))->toArray($request)]);
    }

    public function show(Request $request, string $plan): JsonResponse
    {
        $found = DeploymentPlan::query()->findOrFail($plan);

        return response()->json(['data' => (new DeploymentPlanResource($found))->toArray($request)]);
    }

    public function approve(ApprovePlanRequest $request, string $plan, ApproveDeploymentPlan $approve): JsonResponse
    {
        $found = DeploymentPlan::query()->with('server')->findOrFail($plan);

        // A destructive plan is approved with the machine's name typed, the
        // same ceremony as clearing it for a wipe, because approving is the
        // last human decision before one.
        if ($found->is_destructive && $request->input('confirm_name') !== $found->server?->name) {
            return response()->json([
                'error' => ['code' => 'confirmation_required', 'message' => 'A destructive plan is approved by typing the machine\'s name.'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $approve->execute($found, $request->user(), $request->string('reason')->value());
        } catch (DeploymentRefused $refusal) {
            return Refusals::deployment($refusal);
        }

        return response()->json(['data' => (new DeploymentPlanResource($found->refresh()))->toArray($request)]);
    }

    public function revokeApproval(RevokeApprovalRequest $request, string $plan, RevokeDeploymentApproval $revoke): JsonResponse
    {
        $found = DeploymentPlan::query()->findOrFail($plan);
        $approval = $found->standingApproval();

        if ($approval === null) {
            return Refusals::deployment(DeploymentRefused::notApproved((string) $found->server?->name, $found->fingerprint));
        }

        $revoke->execute($approval, $request->user(), $request->string('reason')->value());

        return response()->json(['data' => (new DeploymentPlanResource($found->refresh()))->toArray($request)]);
    }
}
