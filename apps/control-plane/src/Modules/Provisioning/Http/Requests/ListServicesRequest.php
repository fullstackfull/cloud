<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Lynomia\Http\Concerns\BoundsPageSize;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Provisioning\Domain\Enums\CustomerServiceState;

/**
 * Filtering and paging for the service list.
 *
 * Note what is not here: no customer id, no account id, no service id from
 * another account. Which account's services are listed is decided by the
 * acting-customer middleware, and a query string that named one would be a
 * request to read somebody else's estate.
 *
 * `state` is validated against the customer-facing vocabulary rather than
 * against the internal ServiceStatus, so a client can filter by exactly the
 * words the response prints. `under_review` is one of them, and it is the
 * whole reason this is not just `?status=`.
 *
 * `kind` reuses the catalogue's ProductKind rather than an in-line list of
 * strings, so a fourth product line cannot become filterable in the catalogue
 * and silently unfilterable here.
 */
final class ListServicesRequest extends FormRequest
{
    use BoundsPageSize;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Validated as an integer but not bounded here; see BoundsPageSize.
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'state' => ['sometimes', 'nullable', new Enum(CustomerServiceState::class)],
            'kind' => ['sometimes', 'nullable', new Enum(ProductKind::class)],
        ];
    }

    public function state(): ?CustomerServiceState
    {
        $state = $this->validated()['state'] ?? null;

        return is_string($state) && $state !== '' ? CustomerServiceState::from($state) : null;
    }

    public function kind(): ?ProductKind
    {
        $kind = $this->validated()['kind'] ?? null;

        return is_string($kind) && $kind !== '' ? ProductKind::from($kind) : null;
    }
}
