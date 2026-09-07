<?php

declare(strict_types=1);

namespace Lynomia\Modules\ApiKeys\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Http\Concerns\BoundsPageSize;
use Lynomia\Modules\ApiKeys\Domain\Enums\ApiTokenStatus;

/**
 * Paging and one filter for the token list.
 *
 * No user id and no customer id, by construction. Whose tokens are listed is
 * decided by the authenticated principal and the acting-customer middleware; a
 * query string naming either would be a request to read somebody else's
 * credentials.
 */
final class ListApiTokensRequest extends FormRequest
{
    use BoundsPageSize;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Validated as an integer but not bounded here: the bound is
            // applied by clamping, see BoundsPageSize. This rule only stops
            // "per_page=banana" reaching it.
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', Rule::enum(ApiTokenStatus::class)],
        ];
    }

    public function status(): ?ApiTokenStatus
    {
        $status = $this->validated()['status'] ?? null;

        return is_string($status) && $status !== '' ? ApiTokenStatus::from($status) : null;
    }
}
