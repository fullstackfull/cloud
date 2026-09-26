<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Application\Actions\ChangeComputeNodeStatus;

/**
 * Putting a discovered hypervisor node into service, or taking it out.
 *
 * The id is resolved here rather than by route model binding, so the
 * permission middleware answers before anything says whether the id exists
 * (AdminSurfaceTest).
 */
final class ComputeNodeController
{
    public function status(Request $request, string $node, ChangeComputeNodeStatus $change): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_map(
                static fn (NodeStatus $status): string => $status->value,
                ChangeComputeNodeStatus::SETTABLE,
            ))],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        /** @var ComputeNode $found */
        $found = ComputeNode::query()->findOrFail($node);

        /** @var User $operator */
        $operator = $request->user();

        $changed = $change->execute(
            $found,
            NodeStatus::from((string) $validated['status']),
            (string) $validated['reason'],
            $operator,
        );

        return response()->json([
            'data' => [
                'id' => (string) $changed->getKey(),
                'provider_name' => $changed->provider_name,
                'cluster_id' => (string) $changed->cluster_id,
                'status' => $changed->status->value,
                'is_healthy' => $changed->is_healthy,
                // What placement will actually do with it: active and healthy.
                'schedulable' => $changed->isSchedulable(),
            ],
        ]);
    }
}
