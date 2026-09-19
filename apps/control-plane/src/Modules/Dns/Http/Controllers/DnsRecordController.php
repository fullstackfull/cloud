<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Dns\Application\Actions\AddRecord;
use Lynomia\Modules\Dns\Application\Actions\ChangeRecord;
use Lynomia\Modules\Dns\Application\Actions\RemoveRecord;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Http\Requests\AddRecordRequest;
use Lynomia\Modules\Dns\Http\Requests\ChangeRecordRequest;
use Lynomia\Modules\Dns\Http\Resources\DnsRecordResource;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Dns\Infrastructure\Queries\CustomerZones;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;

/**
 * The records in one of an account's zones.
 *
 * Nested under the zone in the path, because a record is only ever *of* a
 * zone, and because it makes the account check one lookup rather than two: the
 * zone is scoped to the acting customer and the record is scoped to the zone,
 * so a record id from another account cannot be reached even by an id that
 * happens to exist.
 */
final class DnsRecordController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly AddRecord $add,
        private readonly ChangeRecord $change,
        private readonly RemoveRecord $remove,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    public function index(Request $request, string $zone): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $found = $this->scopedZone($zone);

        $records = DnsRecord::query()
            ->where('dns_zone_id', $found->getKey())
            ->where('state', '!=', DnsState::Deleted->value)
            ->orderBy('name')
            ->orderBy('type')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => DnsRecordResource::collection($records),
            'meta' => ['total' => $records->count()],
        ]);
    }

    public function store(AddRecordRequest $request, string $zone): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scopedZone($zone);

        $record = app(RecordActAtomically::class)->execute(
            fn () => $this->add->execute(
                zone: $found,
                type: $request->type(),
                name: (string) $request->input('name'),
                content: $request->content(),
                ttl: $request->ttl(),
                priority: $request->priority(),
                data: $request->structured(),
            ),
            fn (DnsRecord $written) => new AuditedAct(
                action: AuditAction::DnsRecordCreated,
                subject: $written,
                customerId: (string) $found->customer_id,
                context: [
                    'zone' => $found->name,
                    'type' => $written->type->value,
                    'name' => $written->name,
                ],
            ),
        );

        // Re-read: the publish runs the moment the transaction commits, and
        // the in-memory row still says `pending`.
        return (new DnsRecordResource($record->refresh()))->response()->setStatusCode(201);
    }

    public function update(ChangeRecordRequest $request, string $zone, string $record): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scopedZone($zone);
        $row = $this->scopedRecord($found, $record);

        /** @var array<string, mixed> $data */
        $data = $row->type === DnsRecordType::CAA ? (array) $request->input('data', []) : [];

        if ($data !== []) {
            $data = [
                'flags' => (int) ($data['flags'] ?? 0),
                'tag' => (string) ($data['tag'] ?? ''),
                'value' => (string) ($data['value'] ?? ''),
            ];
        }

        $content = $data === []
            ? (string) $request->input('content', '')
            : sprintf('%d %s "%s"', $data['flags'], $data['tag'], $data['value']);

        // The old value, read before the change, so the audit entry says what
        // was replaced. An entry recording only the new value answers "what
        // does it say" — which the row already answers — instead of "what did
        // this person do", which is the question an audit trail is for.
        $before = $row->content;

        $changed = app(RecordActAtomically::class)->execute(
            fn () => $this->change->execute(
                record: $row,
                content: $content,
                ttl: (int) $request->input('ttl', $row->ttl),
                priority: $request->has('priority') ? $this->nullableInt($request->input('priority')) : $row->priority,
                data: $data,
            ),
            fn (DnsRecord $written) => new AuditedAct(
                action: AuditAction::DnsRecordUpdated,
                subject: $written,
                customerId: (string) $found->customer_id,
                context: [
                    'zone' => $found->name,
                    'type' => $written->type->value,
                    'name' => $written->name,
                    'was' => $before,
                ],
            ),
        );

        return (new DnsRecordResource($changed->refresh()))->response();
    }

    public function destroy(Request $request, string $zone, string $record): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scopedZone($zone);
        $row = $this->scopedRecord($found, $record);

        $removed = app(RecordActAtomically::class)->execute(
            fn () => $this->remove->execute($row),
            fn (DnsRecord $written) => new AuditedAct(
                action: AuditAction::DnsRecordDeleted,
                subject: $written,
                customerId: (string) $found->customer_id,
                context: [
                    'zone' => $found->name,
                    'type' => $written->type->value,
                    'name' => $written->name,
                    'was' => $written->content,
                ],
            ),
        );

        return (new DnsRecordResource($removed->refresh()))->response();
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function scopedZone(string $zone): DnsZone
    {
        /** @var DnsZone $found */
        $found = CustomerZones::identified(
            CustomerZones::of((string) $this->actingCustomer->id()),
            $zone,
        )->firstOrFail();

        return $found;
    }

    private function scopedRecord(DnsZone $zone, string $record): DnsRecord
    {
        /** @var DnsRecord $found */
        $found = DnsRecord::query()
            ->where('dns_zone_id', $zone->getKey())
            ->where('state', '!=', DnsState::Deleted->value)
            ->whereKey($record)
            ->firstOrFail();

        return $found;
    }
}
