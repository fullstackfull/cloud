<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Dns\Domain\DTOs\ZoneImportEntry;
use Lynomia\Modules\Dns\Domain\DTOs\ZoneImportPlan;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\ZoneChangeKind;
use Lynomia\Modules\Dns\Domain\Enums\ZoneImportMode;
use Lynomia\Modules\Dns\Domain\Enums\ZoneImportOutcome;
use Lynomia\Modules\Dns\Domain\Exceptions\ZoneFileRefusedException;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZoneImport;

/**
 * Apply exactly the plan that was previewed, through the same actions a
 * single record goes through.
 *
 * The plan is recomputed from the same text against the zone as it is now,
 * and its fingerprint must equal the one the preview handed out. A zone
 * that changed in between — a record added from another tab, a publish that
 * failed — is a 409 that says "preview again". A plan with a refused line
 * is a 409 that says which. Neither writes anything.
 *
 * Removals first, then updates, then additions, so a replace that swaps an
 * A for a CNAME at the same name does not trip the CNAME-stands-alone rule
 * halfway through. Every write goes through AddRecord, ChangeRecord or
 * RemoveRecord: nothing is bulk-written to the tables, every record gets
 * its own publish to the provider and its own state, and a provider that
 * refuses one record leaves that one record failed rather than the import.
 *
 * Every attempt to apply — applied, refused, plan changed — is recorded as
 * a row, which is what the import metric counts. Each record written also
 * gets the audit row it would have got one at a time, marked as an import,
 * so the import is not a way round the per-record trail.
 */
final readonly class ApplyZoneImport
{
    public function __construct(
        private PlanZoneImport $planner,
        private AddRecord $add,
        private ChangeRecord $change,
        private RemoveRecord $remove,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @return array{plan: ZoneImportPlan, added: int, updated: int, removed: int, unchanged: int}
     */
    public function execute(DnsZone $zone, string $text, ZoneImportMode $mode, string $fingerprint, ?string $userId): array
    {
        $plan = $this->planner->execute($zone, $text, $mode);

        if (! hash_equals($plan->fingerprint, $fingerprint)) {
            throw ZoneFileRefusedException::planChanged();
        }

        if (! $plan->isApplicable()) {
            throw ZoneFileRefusedException::planNotApplicable($plan->count(ZoneChangeKind::Refused));
        }

        return DB::transaction(function () use ($zone, $plan, $userId): array {
            $customerId = (string) $zone->customer_id;

            foreach ($plan->of(ZoneChangeKind::Remove) as $entry) {
                $removed = $this->remove->execute($this->record($entry));
                $this->audit->execute(AuditAction::DnsRecordDeleted, $removed, $customerId, $this->context($zone, $entry));
            }

            foreach ($plan->of(ZoneChangeKind::Update) as $entry) {
                $changed = $this->change->execute(
                    $this->record($entry),
                    (string) $entry->content,
                    (int) $entry->ttl,
                    $entry->priority,
                    $entry->type === DnsRecordType::CAA ? $entry->data : [],
                );
                $this->audit->execute(AuditAction::DnsRecordUpdated, $changed, $customerId, $this->context($zone, $entry));
            }

            foreach ($plan->of(ZoneChangeKind::Add) as $entry) {
                $added = $this->add->execute(
                    $zone,
                    $entry->type ?? DnsRecordType::A,
                    (string) $entry->name,
                    (string) $entry->content,
                    (int) $entry->ttl,
                    $entry->priority,
                    $entry->type === DnsRecordType::CAA ? $entry->data : [],
                );
                $this->audit->execute(AuditAction::DnsRecordCreated, $added, $customerId, $this->context($zone, $entry));
            }

            $this->ledger($zone, $plan, ZoneImportOutcome::Applied, $userId);

            return [
                'plan' => $plan,
                'added' => $plan->count(ZoneChangeKind::Add),
                'updated' => $plan->count(ZoneChangeKind::Update),
                'removed' => $plan->count(ZoneChangeKind::Remove),
                'unchanged' => $plan->count(ZoneChangeKind::Unchanged),
            ];
        });
    }

    /**
     * An attempt that ended in a refusal, recorded after the transaction it
     * refused inside has been rolled back — which is why the caller does it
     * from outside, and why the plan is computed again here.
     */
    public function noteRefusal(DnsZone $zone, string $text, ZoneImportMode $mode, ZoneImportOutcome $outcome, ?string $userId): void
    {
        $this->ledger($zone, $this->planner->execute($zone, $text, $mode), $outcome, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(DnsZone $zone, ZoneImportEntry $entry): array
    {
        return [
            'zone' => $zone->name,
            'type' => $entry->type?->value,
            'name' => $entry->name,
            'content' => $entry->content,
            'import' => true,
        ];
    }

    private function record(ZoneImportEntry $entry): DnsRecord
    {
        return DnsRecord::query()->findOrFail((string) $entry->existingId);
    }

    private function ledger(DnsZone $zone, ZoneImportPlan $plan, ZoneImportOutcome $outcome, ?string $userId): void
    {
        DnsZoneImport::query()->create([
            'dns_zone_id' => $zone->getKey(),
            'customer_id' => $zone->customer_id,
            'mode' => $plan->mode,
            'outcome' => $outcome,
            'added' => $plan->count(ZoneChangeKind::Add),
            'updated' => $plan->count(ZoneChangeKind::Update),
            'removed' => $plan->count(ZoneChangeKind::Remove),
            'unchanged' => $plan->count(ZoneChangeKind::Unchanged),
            'refused' => $plan->count(ZoneChangeKind::Refused),
            'ignored' => $plan->count(ZoneChangeKind::Ignored),
            'fingerprint' => $plan->fingerprint,
            'requested_by_user_id' => $userId,
        ]);
    }
}
