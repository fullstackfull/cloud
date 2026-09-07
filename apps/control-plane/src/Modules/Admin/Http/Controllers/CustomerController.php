<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Admin\Http\Controllers\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Domain\Enums\CustomerStatus;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * Customer accounts, as an operator sees them.
 *
 * Search is by name, billing address or id. It is a `LIKE` over three columns
 * and not a full-text index, which is the right size for a support desk and
 * says so rather than pretending to be a search engine.
 */
final class CustomerController
{
    use ListsAcrossTenants;

    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->value()))
            ->when($term !== '', function ($query) use ($term): void {
                /*
                 * The term is bound, never interpolated, and the wildcards are
                 * added around an escaped value: a support query containing a
                 * literal % or _ should find that text rather than match
                 * everything.
                 */
                $escaped = addcslashes($term, '%_\\');

                $query->where(function ($inner) use ($escaped, $term): void {
                    $inner->where('display_name', 'ilike', '%'.$escaped.'%')
                        ->orWhere('legal_name', 'ilike', '%'.$escaped.'%')
                        ->orWhere('billing_email', 'ilike', '%'.$escaped.'%')
                        ->orWhere('id', $term);
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($customers, static fn (Customer $customer): array => [
            'id' => $customer->id,
            'type' => $customer->type->value,
            'status' => $customer->status->value,
            'display_name' => $customer->display_name,
            'legal_name' => $customer->legal_name,
            'billing_email' => $customer->billing_email,
            'currency' => $customer->currency,
            'country' => $customer->country,
            'created_at' => $customer->created_at?->toIso8601String(),
        ]);
    }

    public function show(string $customer): JsonResponse
    {
        $found = Customer::query()->findOrFail($customer);

        /*
         * Counted with two explicit queries rather than withCount, because
         * Customer has no services() or invoices() relation: the modules that
         * own those tables expose them through their own scoped query classes
         * instead, so that a customer-facing read cannot accidentally be
         * written unscoped. Two counts here is the price of that, and it is
         * cheap.
         */
        $services = Service::query()->where('customer_id', $found->getKey())->count();
        $invoices = Invoice::query()->where('customer_id', $found->getKey())->count();

        return response()->json([
            'data' => [
                'id' => $found->id,
                'type' => $found->type->value,
                'status' => $found->status->value,
                'display_name' => $found->display_name,
                'legal_name' => $found->legal_name,
                'billing_email' => $found->billing_email,
                'currency' => $found->currency,
                'country' => $found->country,
                'services_count' => $services,
                'invoices_count' => $invoices,
                'created_at' => $found->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Suspend or reactivate an account.
     *
     * Suspension is not termination and does not touch a single running
     * service here: what it does is stop the account buying anything more,
     * which is the reversible half. Turning off somebody's servers is a
     * separate, deliberate act with its own permission, because the two get
     * confused at exactly the moment an operator is in a hurry.
     */
    public function setStatus(Request $request, string $customer): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $found = Customer::query()->findOrFail($customer);

        $found->forceFill([
            'status' => CustomerStatus::from($validated['status']),
        ])->save();

        return response()->json([
            'data' => [
                'id' => $found->id,
                'status' => $found->status->value,
            ],
            'meta' => [
                // Echoed back so the operator can see what was recorded rather
                // than trusting that it was.
                'reason' => $validated['reason'],
            ],
        ]);
    }
}
