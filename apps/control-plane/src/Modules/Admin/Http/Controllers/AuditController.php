<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;

/**
 * The permanent record, read back.
 *
 * Read-only, and that is not an omission to be filled in later: the table is
 * append-only at the model, and offering an endpoint that could amend or
 * delete an entry would make the whole thing decorative. There is no
 * `destroy`, no `update`, and no bulk action.
 *
 * Filtering is by subject, actor, action and customer — the four questions
 * anybody actually arrives with. There is deliberately no free-text search
 * across the context column: it holds redacted payloads, and a search box over
 * them is an invitation to fish through other people's data rather than
 * investigate a specific act.
 */
final class AuditController
{
    use ListsAcrossTenants;

    public function index(Request $request): JsonResponse
    {
        $entries = AuditEntry::query()
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')->value()))
            ->when($request->filled('actor_id'), fn ($q) => $q->where('actor_id', $request->string('actor_id')->value()))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->string('customer_id')->value()))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->string('subject_id')->value()))
            // Newest first, ULID breaking ties: two acts in the same
            // millisecond must not swap places between pages.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($entries, static fn (AuditEntry $entry): array => [
            'id' => (string) $entry->getKey(),
            'action' => $entry->action->value,
            'actor_type' => $entry->actor_type,
            'actor_id' => $entry->actor_id,
            // As it was at the time, which is the point of storing it.
            'actor_label' => $entry->actor_label,
            'subject_type' => $entry->subject_type,
            'subject_id' => $entry->subject_id,
            'customer_id' => $entry->customer_id,
            'context' => $entry->context,
            'ip_address' => $entry->ip_address,
            'created_at' => $entry->created_at->toIso8601String(),
        ]);
    }
}
