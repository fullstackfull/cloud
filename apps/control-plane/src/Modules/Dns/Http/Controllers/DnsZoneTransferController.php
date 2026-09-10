<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Dns\Application\Actions\ApplyZoneImport;
use Lynomia\Modules\Dns\Application\Actions\ExportZone;
use Lynomia\Modules\Dns\Application\Actions\PlanZoneImport;
use Lynomia\Modules\Dns\Domain\DTOs\ZoneImportPlan;
use Lynomia\Modules\Dns\Domain\Enums\ZoneImportOutcome;
use Lynomia\Modules\Dns\Domain\Exceptions\ZoneFileRefusedException;
use Lynomia\Modules\Dns\Http\Requests\ApplyZoneImportRequest;
use Lynomia\Modules\Dns\Http\Requests\PlanZoneImportRequest;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Dns\Infrastructure\Queries\CustomerZones;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;

/**
 * A zone in and out as a file.
 *
 * Reading is `service.view`; the plan and the apply are `service.manage`,
 * because a plan reads every record in the zone even though it writes
 * nothing. The zone is scoped to the acting account before anything else,
 * and another account's zone is a 404, never a 403.
 */
final class DnsZoneTransferController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly PlanZoneImport $planner,
        private readonly ApplyZoneImport $applier,
        private readonly ExportZone $exporter,
    ) {}

    public function plan(PlanZoneImportRequest $request, string $zone): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($zone);

        $plan = $this->planner->execute($found, $request->text(), $request->mode());

        return response()->json(['data' => $plan->toArray()]);
    }

    public function apply(ApplyZoneImportRequest $request, string $zone): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($zone);
        $user = $request->user();

        try {
            /** @var array{plan: ZoneImportPlan, added: int, updated: int, removed: int, unchanged: int} $result */
            $result = $this->applied($request, $found, $user?->getKey());
        } catch (ZoneFileRefusedException $refusal) {
            $outcome = $refusal->errorCode() === 'dns.import.plan_changed' ? ZoneImportOutcome::PlanChanged : ZoneImportOutcome::Refused;
            $this->applier->noteRefusal($found, $request->text(), $request->mode(), $outcome, $user?->getKey());

            throw $refusal;
        }

        return response()->json([
            'data' => [
                'zone_id' => (string) $found->getKey(),
                'mode' => $request->mode()->value,
                'added' => $result['added'],
                'updated' => $result['updated'],
                'removed' => $result['removed'],
                'unchanged' => $result['unchanged'],
            ],
        ]);
    }

    /**
     * @return array{plan: ZoneImportPlan, added: int, updated: int, removed: int, unchanged: int}
     */
    private function applied(ApplyZoneImportRequest $request, DnsZone $found, ?string $userId): array
    {
        /** @var array{plan: ZoneImportPlan, added: int, updated: int, removed: int, unchanged: int} $result */
        $result = app(RecordActAtomically::class)->execute(
            fn (): array => $this->applier->execute($found, $request->text(), $request->mode(), $request->planFingerprint(), $userId),
            fn (array $applied) => new AuditedAct(
                action: AuditAction::DnsZoneImported,
                subject: $found,
                customerId: (string) $found->customer_id,
                context: [
                    'zone' => $found->name,
                    'mode' => $request->mode()->value,
                    'added' => $applied['added'],
                    'updated' => $applied['updated'],
                    'removed' => $applied['removed'],
                    'unchanged' => $applied['unchanged'],
                    'fingerprint' => $request->planFingerprint(),
                ],
            ),
        );

        return $result;
    }

    public function export(Request $request, string $zone): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $found = $this->scoped($zone);

        /** @var array{filename: string, content: string, record_count: int} $export */
        $export = app(RecordActAtomically::class)->execute(
            fn (): array => $this->exporter->execute($found),
            fn (array $exported) => new AuditedAct(
                action: AuditAction::DnsZoneExported,
                subject: $found,
                customerId: (string) $found->customer_id,
                context: ['zone' => $found->name, 'record_count' => $exported['record_count']],
            ),
        );

        return response()->json(['data' => $export]);
    }

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    private function scoped(string $zone): DnsZone
    {
        /** @var DnsZone $found */
        $found = CustomerZones::identified(
            CustomerZones::of((string) $this->actingCustomer->id()),
            $zone,
        )->firstOrFail();

        return $found;
    }
}
