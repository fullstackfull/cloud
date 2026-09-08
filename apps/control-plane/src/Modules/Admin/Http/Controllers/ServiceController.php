<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Dedicated\Application\Actions\DecommissionDedicatedServer;
use Lynomia\Modules\Dedicated\Application\Actions\ReturnDedicatedServerToStock;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Vps\Application\Actions\TerminateVpsService;

/**
 * Ending a service, which is the one act on this surface that destroys data.
 *
 * Shared hosting has had an operator-driven termination since Phase 29, with a
 * retention window and a deliberate override. A VPS had none: `destroy_vps`
 * was a job kind with no handler, `ReleaseReason::ServiceTerminated` had no
 * producer, and a cancelled customer's machine, address and share of a node's
 * capacity were held for ever. This is the other half of that pair.
 *
 * The override is a second permission check rather than a flag, for the same
 * reason it is in the hosting controller: "terminate what has expired" and
 * "delete a live customer's data today" are different decisions, and a role
 * that may do the first should not thereby be able to do the second.
 */
final class ServiceController
{
    public function terminate(Request $request, string $service): JsonResponse
    {
        $found = Service::query()->findOrFail($service);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $force = (bool) ($validated['force'] ?? false);

        if ($force) {
            /*
             * Skipping the retention window destroys data a customer might
             * still be about to pay for. It needs the authority to terminate,
             * asserted a second time and separately from the authority to
             * queue an ordinary expiry.
             */
            abort_unless(
                $request->user()?->can(Permission::ServiceTerminate->value) === true,
                403,
            );
        }

        $user = $request->user();

        $terminatedBy = $user instanceof User
            ? sprintf('%s <%s>', $user->name, $user->email)
            : 'system';

        /*
         * A dedicated server ends differently, and the difference is physical.
         * A machine's disks hold the customer's data until somebody erases
         * them, and no call this platform can make proves that happened — so
         * the server leaves the customer and goes to maintenance rather than
         * back into stock, and a second, deliberate act returns it. There is
         * nothing to queue: no provider is asked to destroy anything.
         */
        if ($found->kind === ProductKind::Dedicated->value) {
            $server = app(RecordActAtomically::class)->execute(
                act: static fn (): DedicatedServer => app(DecommissionDedicatedServer::class)
                    ->execute($found, force: $force),
                describe: static fn (DedicatedServer $decommissioned): AuditedAct => new AuditedAct(
                    action: AuditAction::ServiceTerminated,
                    subject: $found,
                    customerId: $found->customer_id,
                    context: [
                        'reason' => $validated['reason'],
                        'forced' => $force,
                        'kind' => $found->kind,
                        'dedicated_server_id' => (string) $decommissioned->getKey(),
                        'serial' => $decommissioned->serial,
                        'terminated_by' => $terminatedBy,
                    ],
                ),
            );

            return response()->json([
                'data' => [
                    'service_id' => (string) $found->getKey(),
                    'status' => $found->fresh()?->status->value,
                    'provisioning_job_id' => null,
                    // Nothing is queued, and the machine is not back in stock:
                    // it is held in maintenance until an operator says its
                    // disks have been erased.
                    'queued' => false,
                    'dedicated_server_status' => $server->status->value,
                ],
            ], 202);
        }

        $job = app(RecordActAtomically::class)->execute(
            act: static fn (): ProvisioningJob => app(TerminateVpsService::class)->execute($found, force: $force),
            describe: static fn (ProvisioningJob $queued): AuditedAct => new AuditedAct(
                action: AuditAction::ServiceTerminated,
                subject: $found,
                customerId: $found->customer_id,
                context: [
                    'reason' => $validated['reason'],
                    'forced' => $force,
                    'kind' => $found->kind,
                    'provisioning_job_id' => (string) $queued->getKey(),
                    'terminated_by' => $terminatedBy,
                ],
            ),
        );

        return response()->json([
            'data' => [
                'service_id' => (string) $found->getKey(),
                'status' => $found->fresh()?->status->value,
                'provisioning_job_id' => (string) $job->getKey(),
                // Queued, not done: the machine is destroyed by a worker, and
                // the service reaches `terminated` when that worker succeeds.
                'queued' => true,
                'dedicated_server_status' => null,
            ],
        ], 202);
    }

    /**
     * A decommissioned machine goes back on the shelf.
     *
     * The second half of ending a dedicated service, and separate on purpose:
     * the platform cannot verify that a physical disk was erased, so what this
     * records is a person's word for it — which is why they have to say what
     * they did, beside their name, in the trail.
     */
    public function returnToStock(Request $request, string $server): JsonResponse
    {
        $found = DedicatedServer::query()->findOrFail($server);

        $validated = $request->validate([
            'evidence' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $user = $request->user();

        $returned = app(RecordActAtomically::class)->execute(
            act: static fn (): DedicatedServer => app(ReturnDedicatedServerToStock::class)->execute($found),
            describe: static fn (DedicatedServer $stock): AuditedAct => new AuditedAct(
                action: AuditAction::DedicatedServerReturnedToStock,
                subject: $stock,
                context: [
                    'evidence' => $validated['evidence'],
                    'serial' => $stock->serial,
                    'returned_by' => $user instanceof User
                        ? sprintf('%s <%s>', $user->name, $user->email)
                        : 'system',
                ],
            ),
        );

        return response()->json([
            'data' => [
                'dedicated_server_id' => (string) $returned->getKey(),
                'status' => $returned->status->value,
            ],
        ]);
    }
}
