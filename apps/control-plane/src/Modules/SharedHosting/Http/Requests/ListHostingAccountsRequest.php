<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Lynomia\Http\Concerns\BoundsPageSize;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;

/**
 * Filtering and paging for the hosting account list.
 *
 * Note what is not here: no customer id, no node, no node slug, no panel. Whose
 * accounts are listed is decided by the acting-customer middleware from the
 * authenticated principal, and a query string naming an account would be a
 * request to read somebody else's hosting. Node and panel are not filterable
 * because which machine an account sits on is an operational fact about the
 * platform and about the other tenants sharing that machine.
 */
final class ListHostingAccountsRequest extends FormRequest
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
            'status' => ['sometimes', 'nullable', new Enum(HostingAccountStatus::class)],
        ];
    }

    public function status(): ?HostingAccountStatus
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $status = $validated['status'] ?? null;

        return is_string($status) && $status !== '' ? HostingAccountStatus::from($status) : null;
    }
}
