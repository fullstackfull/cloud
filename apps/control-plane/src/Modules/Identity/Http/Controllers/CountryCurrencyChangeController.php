<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Identity\Application\Actions\ReanalyseCountryCurrencyChange;
use Lynomia\Modules\Identity\Application\Actions\RequestCountryCurrencyChange;
use Lynomia\Modules\Identity\Application\Actions\WithdrawCountryCurrencyChange;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Http\Requests\RequestCountryCurrencyChangeRequest;
use Lynomia\Modules\Identity\Http\Resources\CountryCurrencyChangeResource;
use Lynomia\Modules\Identity\Infrastructure\Models\CountryCurrencyChange;

/**
 * The customer's side of changing the account's country or currency.
 *
 * `customer.manage`, which is the owner and the administrator: this is a
 * decision about what the whole account is billed in, not a service. The
 * list carries the currencies the catalogue prices anything in, so the
 * screen offers what can actually be sold rather than a free text box.
 */
final class CountryCurrencyChangeController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly RequestCountryCurrencyChange $request,
        private readonly ReanalyseCountryCurrencyChange $reanalyse,
        private readonly WithdrawCountryCurrencyChange $withdraw,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'customer.manage');

        $rows = CountryCurrencyChange::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        /** @var list<string> $currencies */
        $currencies = PlanPrice::query()
            ->where('is_active', true)
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->map(static fn (string $c): string => strtoupper($c))
            ->values()
            ->all();

        return response()->json([
            'data' => CountryCurrencyChangeResource::collection($rows),
            'meta' => ['currencies' => $currencies],
        ]);
    }

    public function store(RequestCountryCurrencyChangeRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'customer.manage');

        $customer = $this->actingCustomer->get();
        $userId = $request->user()?->getAuthIdentifier();

        /** @var CountryCurrencyChange $change */
        $change = app(RecordActAtomically::class)->execute(
            act: fn (): CountryCurrencyChange => $this->request->execute(
                $customer,
                $request->country(),
                $request->currency(),
                $request->reason(),
                $userId === null ? null : (string) $userId,
            ),
            describe: static fn (CountryCurrencyChange $created): AuditedAct => new AuditedAct(
                action: AuditAction::CountryCurrencyChangeRequested,
                subject: $created,
                customerId: (string) $created->customer_id,
                context: [
                    'from' => ['country' => $created->from_country, 'currency' => $created->from_currency],
                    'to' => ['country' => $created->to_country, 'currency' => $created->to_currency],
                    'state' => $created->state->value,
                    'blockers' => $created->impact['blockers'],
                ],
            ),
        );

        return (new CountryCurrencyChangeResource($change))->response()->setStatusCode(201);
    }

    public function reanalyse(Request $request, string $change): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'customer.manage');

        return response()->json(['data' => new CountryCurrencyChangeResource($this->reanalyse->execute($this->scoped($change)))]);
    }

    public function withdraw(Request $request, string $change): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'customer.manage');

        $found = $this->scoped($change);

        /** @var CountryCurrencyChange $withdrawn */
        $withdrawn = app(RecordActAtomically::class)->execute(
            act: fn (): CountryCurrencyChange => $this->withdraw->execute($found),
            describe: static fn (CountryCurrencyChange $row): AuditedAct => new AuditedAct(
                action: AuditAction::CountryCurrencyChangeWithdrawn,
                subject: $row,
                customerId: (string) $row->customer_id,
                context: ['to' => ['country' => $row->to_country, 'currency' => $row->to_currency]],
            ),
        );

        return response()->json(['data' => new CountryCurrencyChangeResource($withdrawn)]);
    }

    private function scoped(string $id): CountryCurrencyChange
    {
        /** @var CountryCurrencyChange $found */
        $found = CountryCurrencyChange::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->whereKey($id)
            ->firstOrFail();

        return $found;
    }
}
