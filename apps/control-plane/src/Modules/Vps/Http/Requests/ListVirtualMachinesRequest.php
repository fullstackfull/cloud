<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Lynomia\Http\Concerns\BoundsPageSize;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;

/**
 * Filtering and paging for the machine list.
 *
 * Note what is not here: no customer id, no service id, no node, no cluster.
 * Whose machines are listed is decided by the acting-customer middleware, and
 * a query string naming an account would be a request to read somebody else's
 * fleet. Node and cluster are not filterable because they are not the
 * customer's business — which node a machine sits on is an operational detail
 * that also happens to describe other tenants' neighbours.
 */
final class ListVirtualMachinesRequest extends FormRequest
{
    use BoundsPageSize;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Validated as an integer but deliberately not bounded here; the
            // clamp in BoundsPageSize is what enforces the ceiling.
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'power_state' => ['sometimes', 'nullable', new Enum(PowerState::class)],
        ];
    }

    public function powerState(): ?PowerState
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $state = $validated['power_state'] ?? null;

        return is_string($state) && $state !== '' ? PowerState::from($state) : null;
    }
}
