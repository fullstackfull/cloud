<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;

/**
 * The names the platform holds, from the operator's side.
 *
 * ---------------------------------------------------------------------------
 * What this exists to make findable
 * ---------------------------------------------------------------------------
 *
 * Two queues, and both are made of rows nothing else will clear.
 *
 * **Names the platform is unsure of.** The Timeout Rule leaves `indeterminate`
 * rows on purpose, and reconciliation settles most of them by asking the
 * registry. The ones it cannot settle — a registrar that cannot be inspected,
 * a name the registry says is not ours — end here, and a person decides. A
 * queue nobody can list is a queue nobody works.
 *
 * **Names about to lapse.** A domain that expires is not suspended, it is
 * gone, and the customer usually finds out when their mail stops. This is the
 * list an operator can act on while acting is still possible.
 *
 * Read-only. Nothing here registers, renews or transfers, and nothing here
 * changes a customer's domain: an operator who needs to do that does it
 * through the same paths the customer uses, so that one set of rules about
 * money and idempotency applies to everybody.
 */
final class DomainsController
{
    use ListsAcrossTenants;

    public function index(Request $request): JsonResponse
    {
        $state = $request->string('state')->value();
        $customerId = $request->string('customer_id')->value();
        $onlyAttention = $request->boolean('needs_attention');
        $expiringWithin = $request->integer('expiring_within_days');

        $domains = Domain::query()
            ->when($state !== '', fn ($query) => $query->where('state', $state))
            ->when($customerId !== '', fn ($query) => $query->where('customer_id', $customerId))
            ->when($onlyAttention, fn ($query) => $query->whereIn('state', [
                DomainState::Indeterminate->value,
                DomainState::NeedsReview->value,
            ]))
            ->when(
                $expiringWithin > 0,
                fn ($query) => $query
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now()->addDays($expiringWithin)),
            )
            ->orderByRaw('expires_at is null, expires_at asc')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->paginated($domains, fn (Domain $domain): array => [
            'id' => (string) $domain->getKey(),
            'customer_id' => (string) $domain->customer_id,
            'name' => $domain->name,
            'tld' => $domain->tld,
            'state' => $domain->state->value,
            'needs_attention' => $domain->state->needsAttention(),

            /*
             * Published to operators and to nobody else. Which registrar holds
             * a name is the platform's own arrangement on the customer
             * surface, and the first thing an operator needs to know here.
             */
            'provider' => $domain->provider,
            'provider_reference' => $domain->provider_reference,

            'expires_at' => $domain->expires_at?->toIso8601String(),
            'auto_renew' => $domain->auto_renew,
            'review_reason' => $domain->review_reason,
            'reconciled_at' => $domain->reconciled_at?->toIso8601String(),
        ]);
    }

    /**
     * Attempts that spent money and did not finish.
     *
     * Defaults to the ones that need a person, because the completed ones are
     * a ledger and these are a queue.
     */
    public function operations(Request $request): JsonResponse
    {
        $state = $request->string('state')->value();
        $kind = $request->string('kind')->value();
        $onlyAttention = $request->boolean('needs_attention');

        $operations = DomainOperation::query()
            ->when($state !== '', fn ($query) => $query->where('state', $state))
            ->when($kind !== '', fn ($query) => $query->where('kind', $kind))
            ->when($onlyAttention, fn ($query) => $query->whereIn('state', [
                DomainOperationState::Indeterminate->value,
                DomainOperationState::NeedsReview->value,
                DomainOperationState::AwaitingRegistry->value,
            ]))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return $this->paginated($operations, fn (DomainOperation $operation): array => [
            'id' => (string) $operation->getKey(),
            'domain_id' => $operation->domain_id === null ? null : (string) $operation->domain_id,
            'customer_id' => (string) $operation->customer_id,
            'name' => $operation->name,
            'kind' => $operation->kind->value,
            'state' => $operation->state->value,
            'needs_attention' => $operation->state->needsAttention(),
            'term_years' => $operation->term_years,
            'currency' => $operation->currency,

            // Both sides, because the question an operator is answering is
            // usually "what did this cost us and what did we charge".
            'price_minor' => $operation->price_minor,
            'cost_minor' => $operation->cost_minor,

            'provider' => $operation->provider,
            'provider_reference' => $operation->provider_reference,
            'invoice_id' => $operation->invoice_id === null ? null : (string) $operation->invoice_id,
            'attempts' => $operation->attempts,
            'failure_code' => $operation->failure_code,
            'failure_message' => $operation->failure_message,
            'created_at' => $operation->created_at->toIso8601String(),
            'completed_at' => $operation->completed_at?->toIso8601String(),
        ]);
    }
}
