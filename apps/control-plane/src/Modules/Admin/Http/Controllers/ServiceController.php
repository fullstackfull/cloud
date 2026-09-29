<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Dedicated\Application\Actions\RetireDedicatedServer;
use Lynomia\Modules\Dedicated\Application\Actions\ReturnDedicatedServerToStock;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Support\Lifecycle\EndOfService;

/**
 * Ending a service, which is the one act on this surface that destroys data.
 *
 * Shared hosting has had an operator-driven termination since Phase 29, with a
 * retention window and a deliberate override. A VPS had none: `destroy_vps`
 * was a job kind with no handler, `ReleaseReason::ServiceTerminated` had no
 * producer, and a cancelled customer's machine, address and share of a node's
 * capacity were held for ever. This is the other half of that pair.
 *
 * The retention window is each kind's own action's to enforce, and `force` is
 * the only way past it. Who may send `force` is not a separate grant here:
 * every permission the kind needs is asked on every call (see terminate()),
 * because a check that runs only under `force` is a check the unforced call
 * walks around — which is how F-18 happened on the hosting route.
 */
final class ServiceController
{
    use ListsAcrossTenants;

    /**
     * Every service, and why the stuck ones are stuck.
     *
     * ---------------------------------------------------------------------
     * The reason nobody could read
     * ---------------------------------------------------------------------
     *
     * `placement_blocked_reason` has been written into a service's resources
     * for as long as ProvisionOrderedService has existed, and until now there
     * was no way to read it that did not involve a SQL client. A paid customer
     * whose machine could not be placed was a row in a JSON column that no
     * screen and no endpoint mentioned. The audit found the value written and
     * effectively unread.
     *
     * Checkout now refuses what the platform knows it cannot place, so this
     * should be a short list. It will not always be empty: configuration can
     * be removed between a payment and a build, and that race is covered by
     * keeping the paid service visible rather than by pretending it cannot
     * happen. `?blocked=1` is the filter an operator actually wants.
     *
     * Published for operators only, and it says what it says: the reason names
     * a cluster, an IP pool or a panel package, which is exactly the internal
     * topology the customer-facing resource is careful never to expose.
     */
    public function index(Request $request): JsonResponse
    {
        $services = Service::query()
            ->when(
                $request->boolean('blocked'),
                static fn ($query) => $query->whereNotNull('resources->placement_blocked_reason'),
            )
            ->when(
                $request->filled('status'),
                static fn ($query) => $query->where('status', $request->string('status')->value()),
            )
            ->when(
                $request->filled('customer_id'),
                static fn ($query) => $query->where('customer_id', $request->string('customer_id')->value()),
            )
            /*
             * Newest first, with the ULID breaking ties: two services created
             * in the same millisecond by one order must not swap places
             * between pages.
             */
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($services, static function (Service $service): array {
            $resources = (array) $service->resources;

            return [
                'id' => (string) $service->getKey(),
                'customer_id' => $service->customer_id,
                'order_id' => $service->order_id,
                'order_item_id' => $service->order_item_id,
                'subscription_id' => $service->subscription_id,
                'plan_id' => $service->plan_id,
                'kind' => $service->kind,
                'label' => $service->label,
                'status' => $service->status->value,
                'needs_attention' => $service->status->needsAttention(),
                'placement_blocked_reason' => $resources['placement_blocked_reason'] ?? null,
                'created_at' => $service->created_at?->toIso8601String(),
                'updated_at' => $service->updated_at?->toIso8601String(),
            ];
        });
    }

    /**
     * Ending a service, through the same door the retention sweep uses.
     *
     * ---------------------------------------------------------------------
     * One table, not two (F-19)
     * ---------------------------------------------------------------------
     *
     * This method used to decide for itself how each kind ends — `dedicated`,
     * else a VPS — while EndOfService made the same decision for the sweep. A
     * shared-hosting service fell into the VPS branch and was refused for
     * having no virtual machine, and a VPS whose build had failed could not be
     * ended at all. Both now go through EndOfService, and so does the question
     * of who may ask.
     *
     * ---------------------------------------------------------------------
     * The permission, and what `force` does not change
     * ---------------------------------------------------------------------
     *
     * The route demands `service.terminate`. EndOfService::authorityOver()
     * names everything the kind needs, and all of it is asked here, forced or
     * not: for shared hosting that adds `hosting_account.manage`, because this
     * route reaches TerminateHostingAccount — F-18's action layer — without
     * passing F-18's controller gate on the hosting-account route, and must
     * not be the weaker door to the same account. `force` decides whether each
     * kind's suspension-and-window guard applies, and changes no permission;
     * the second permission check it used to guard asked for the permission
     * the route had already demanded.
     *
     * ---------------------------------------------------------------------
     * The act, then its record
     * ---------------------------------------------------------------------
     *
     * The same order as the sweep, and RecordAuditEntry's rule for an act that
     * reaches a provider: the hosting path deletes an account at the panel
     * before this returns, and rolling the platform's rows back because the
     * trail could not be written would leave them describing a site that no
     * longer exists. A trail that cannot be written is a 500 an operator sees.
     */
    public function terminate(Request $request, string $service): JsonResponse
    {
        $found = Service::query()->findOrFail($service);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $force = (bool) ($validated['force'] ?? false);

        foreach (EndOfService::authorityOver($found->kind) as $permission) {
            abort_unless(
                $request->user()?->can($permission->value) === true,
                403,
                'Ending a service of this kind needs '.$permission->value.'.',
            );
        }

        $user = $request->user();

        $ended = app(EndOfService::class)->execute($found, force: $force);

        app(RecordAuditEntry::class)->execute(
            action: AuditAction::ServiceTerminated,
            subject: $found,
            customerId: $found->customer_id,
            context: [
                'reason' => $validated['reason'],
                'forced' => $force,
                'kind' => $found->kind,
                'terminated_by' => $user instanceof User
                    ? sprintf('%s <%s>', $user->name, $user->email)
                    : 'system',
                ...$ended->auditContext(),
            ],
        );

        return response()->json([
            'data' => [
                'service_id' => (string) $found->getKey(),
                'status' => $found->fresh()?->status->value,
                'provisioning_job_id' => $ended->provisioningJobId,
                /*
                 * Queued, not done, for a VPS: the machine is destroyed by a
                 * worker, and the service reaches `terminated` when that worker
                 * succeeds. Nothing is queued for the other endings — a
                 * dedicated server is held in maintenance until an operator
                 * says its disks have been erased, a hosting account is gone
                 * from the panel already, and a service nothing was built for
                 * had nothing to destroy.
                 */
                'queued' => $ended->isQueued(),
                'dedicated_server_status' => $ended->dedicatedServerStatus,
                'hosting_account_id' => $ended->hostingAccountId,
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

    /**
     * A decommissioned machine leaves the fleet instead.
     *
     * The other second half of ending a dedicated service, for a machine that
     * is not going back on the shelf. The same kind of statement as
     * returnToStock() — a person's word, with what they did, beside their name
     * — and the one that starts the quarantine clock on the addresses the
     * machine was holding. Without it, a machine that was never going to be
     * resold kept its addresses held for ever.
     */
    public function retire(Request $request, string $server): JsonResponse
    {
        $found = DedicatedServer::query()->findOrFail($server);

        $validated = $request->validate([
            'evidence' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $user = $request->user();

        $retired = app(RecordActAtomically::class)->execute(
            act: static fn (): DedicatedServer => app(RetireDedicatedServer::class)->execute($found),
            describe: static fn (DedicatedServer $gone): AuditedAct => new AuditedAct(
                action: AuditAction::DedicatedServerRetired,
                subject: $gone,
                context: [
                    'evidence' => $validated['evidence'],
                    'serial' => $gone->serial,
                    'retired_by' => $user instanceof User
                        ? sprintf('%s <%s>', $user->name, $user->email)
                        : 'system',
                ],
            ),
        );

        return response()->json([
            'data' => [
                'dedicated_server_id' => (string) $retired->getKey(),
                'status' => $retired->status->value,
            ],
        ]);
    }
}
