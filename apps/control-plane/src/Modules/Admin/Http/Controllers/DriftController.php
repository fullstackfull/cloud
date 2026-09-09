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
use Lynomia\Modules\Compute\Application\Jobs\ReconcileCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Provisioning\Application\Actions\ReviewDrift;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;

/**
 * The queue an operator works when the platform and a hypervisor disagree.
 *
 * The engine that finds drift, the table that records it, the severity ladder
 * and the de-duplication were all built; there was no way to look at any of it
 * and no way to say "seen" or "fixed". This is that surface.
 *
 * Note what it deliberately does not offer: anything that changes the
 * provider. There is no "delete the orphan", no "rebuild the missing machine",
 * no button that makes a red row green. Every one of those is a distinct
 * operation with its own risk and its own permission, and a screen full of
 * findings is exactly where a one-click remedy gets pressed on the wrong row.
 * An operator can record a verdict, and can ask for a fresh look.
 */
final class DriftController
{
    use ListsAcrossTenants;

    public function index(Request $request): JsonResponse
    {
        $drifts = ResourceDrift::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->value()))
            ->when($request->filled('kind'), fn ($query) => $query->where('kind', $request->string('kind')->value()))
            ->when($request->filled('severity'), fn ($query) => $query->where('severity', $request->string('severity')->value()))
            /*
             * Worst first, then oldest. An operator opening this screen during
             * an incident should see the machines that have gone missing above
             * the ones that are merely powered off unexpectedly.
             */
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->orderBy('first_seen_at')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return $this->paginated($drifts, fn (ResourceDrift $drift): array => $this->present($drift));
    }

    public function review(Request $request, string $drift): JsonResponse
    {
        $found = ResourceDrift::query()->findOrFail($drift);

        $validated = $request->validate([
            'verdict' => ['required', 'string', 'in:acknowledged,resolved'],
            /*
             * Required for a resolution and not for an acknowledgement. "I have
             * seen this" needs no explanation; "this is no longer true" is a
             * claim about the world that the next person to read the row has to
             * be able to evaluate.
             */
            'resolution' => ['required_if:verdict,resolved', 'nullable', 'string', 'min:3', 'max:500'],
        ]);

        $verdict = DriftStatus::from($validated['verdict']);

        /*
         * DriftAlreadyReviewedException is a DomainException carrying its own
         * 409, so it is deliberately not caught here: the renderer turns it
         * into the same shaped error body as everything else, and catching it
         * to re-throw something equivalent is how two error formats for one
         * event get invented.
         */
        $reviewed = app(RecordActAtomically::class)->execute(
            act: static fn (): ResourceDrift => app(ReviewDrift::class)->execute(
                drift: $found,
                verdict: $verdict,
                resolution: $validated['resolution'] ?? null,
                reviewedByUserId: (string) $request->user()?->getAuthIdentifier(),
            ),
            describe: static fn (ResourceDrift $drift): AuditedAct => new AuditedAct(
                action: $verdict === DriftStatus::Resolved
                    ? AuditAction::DriftResolved
                    : AuditAction::DriftAcknowledged,
                subject: $drift,
                context: [
                    'kind' => $drift->kind->value,
                    'severity' => $drift->severity->value,
                    'provider_reference' => $drift->provider_reference,
                    'resolution' => $drift->resolution,
                    'occurrences' => $drift->occurrences,
                ],
            ),
        );

        return response()->json(['data' => $this->present($reviewed)]);
    }

    /**
     * Ask for a fresh comparison of one cluster, now.
     *
     * The reconciler runs every thirty minutes, which is the wrong cadence for
     * somebody on a call with a customer. This queues the same job the
     * scheduler queues — not a different, faster, less careful path — so what
     * the operator triggers is exactly what runs unattended.
     */
    public function reconcile(Request $request, string $cluster): JsonResponse
    {
        $found = ComputeCluster::query()->findOrFail($cluster);

        ReconcileCluster::dispatch((string) $found->getKey());

        app(RecordAuditEntry::class)->execute(
            action: AuditAction::ReconciliationRequested,
            subject: $found,
            context: ['cluster' => $found->name, 'driver' => $found->driver->value],
        );

        return response()->json([
            'data' => [
                'cluster_id' => (string) $found->getKey(),
                'queued' => true,
            ],
        ], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ResourceDrift $drift): array
    {
        return [
            'id' => (string) $drift->getKey(),
            'provider' => $drift->provider,
            'resource_type' => $drift->resource_type,
            'service_id' => $drift->service_id,
            'provider_reference' => $drift->provider_reference,
            'kind' => $drift->kind->value,
            'severity' => $drift->severity->value,
            'status' => $drift->status->value,
            // Already redacted by the model's cast on the way in; published so
            // the operator can see what the two sides actually said.
            'expected' => $drift->expected,
            'observed' => $drift->observed,
            'occurrences' => $drift->occurrences,
            'first_seen_at' => $drift->first_seen_at->toIso8601String(),
            'last_seen_at' => $drift->last_seen_at->toIso8601String(),
            'resolution' => $drift->resolution,
            'resolved_at' => $drift->resolved_at?->toIso8601String(),
        ];
    }
}
