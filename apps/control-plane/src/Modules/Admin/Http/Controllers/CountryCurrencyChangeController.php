<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Application\Actions\DecideCountryCurrencyChange;
use Lynomia\Modules\Identity\Infrastructure\Models\CountryCurrencyChange;

/**
 * The operator's side: the queue of requests, and the decision on each.
 *
 * Approval writes the account only through the same action the sweep
 * uses, after the same last check, so an operator cannot approve past a
 * blocker. The audit row for an approval is written here, around the act;
 * the row for the application itself is written by the action, because
 * the sweep applies scheduled changes with no operator in the request.
 */
final class CountryCurrencyChangeController
{
    use ListsAcrossTenants;

    public function __construct(
        private readonly DecideCountryCurrencyChange $decide,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $state = $request->query('state');

        $rows = CountryCurrencyChange::query()
            ->with('customer:id,display_name,billing_email')
            ->when(is_string($state) && $state !== '', fn ($query) => $query->where('state', $state))
            ->when(! is_string($state) || $state === '', fn ($query) => $query->open())
            ->orderByRaw("case state when 'needs_review' then 0 when 'awaiting_approval' then 1 when 'scheduled' then 2 else 3 end")
            ->orderBy('created_at')
            ->paginate($this->perPage($request));

        return $this->paginated($rows, fn (CountryCurrencyChange $change): array => $this->row($change));
    }

    public function approve(Request $request, string $change): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:500'],
            'apply_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $found = CountryCurrencyChange::query()->findOrFail($change);
        $applyAt = isset($validated['apply_at']) && is_string($validated['apply_at']) ? CarbonImmutable::parse($validated['apply_at']) : null;
        $operator = (string) $request->user()?->getAuthIdentifier();

        /** @var CountryCurrencyChange $decided */
        $decided = app(RecordActAtomically::class)->execute(
            act: fn (): CountryCurrencyChange => $this->decide->approve($found, $operator, $validated['note'], $applyAt),
            describe: static fn (CountryCurrencyChange $row): AuditedAct => new AuditedAct(
                action: AuditAction::CountryCurrencyChangeApproved,
                subject: $row,
                customerId: (string) $row->customer_id,
                context: [
                    'note' => $validated['note'],
                    'scheduled_for' => $row->scheduled_for?->toIso8601String(),
                    'state' => $row->state->value,
                    'to' => ['country' => $row->to_country, 'currency' => $row->to_currency],
                ],
            ),
        );

        return response()->json(['data' => $this->row($decided)]);
    }

    public function reject(Request $request, string $change): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $found = CountryCurrencyChange::query()->findOrFail($change);
        $operator = (string) $request->user()?->getAuthIdentifier();

        /** @var CountryCurrencyChange $decided */
        $decided = app(RecordActAtomically::class)->execute(
            act: fn (): CountryCurrencyChange => $this->decide->reject($found, $operator, $validated['note']),
            describe: static fn (CountryCurrencyChange $row): AuditedAct => new AuditedAct(
                action: AuditAction::CountryCurrencyChangeRejected,
                subject: $row,
                customerId: (string) $row->customer_id,
                context: ['note' => $validated['note'], 'to' => ['country' => $row->to_country, 'currency' => $row->to_currency]],
            ),
        );

        return response()->json(['data' => $this->row($decided)]);
    }

    /**
     * The same shape the customer sees, plus who the customer is. Built
     * here rather than through the Identity resource: a module does not
     * reach into another module's HTTP layer.
     *
     * @return array<string, mixed>
     */
    private function row(CountryCurrencyChange $change): array
    {
        $customer = $change->customer;

        return [
            'id' => $change->id,
            'customer_id' => $change->customer_id,
            'state' => $change->state->value,
            'is_open' => $change->state->isOpen(),
            'needs_attention' => $change->state->needsAttention(),
            'from_country' => $change->from_country,
            'to_country' => $change->to_country,
            'from_currency' => $change->from_currency,
            'to_currency' => $change->to_currency,
            'reason' => $change->reason,
            'impact' => $change->impact,
            'decision_note' => $change->decision_note,
            'analysed_at' => $change->analysed_at->toIso8601String(),
            'decided_at' => $change->decided_at?->toIso8601String(),
            'scheduled_for' => $change->scheduled_for?->toIso8601String(),
            'applied_at' => $change->applied_at?->toIso8601String(),
            'created_at' => $change->created_at->toIso8601String(),
            'customer' => [
                'id' => $change->customer_id,
                'display_name' => $customer?->display_name,
                'billing_email' => $customer?->billing_email,
            ],
        ];
    }
}
