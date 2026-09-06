<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Http\Requests\Concerns\BoundsPageSize;

/**
 * Filtering and paging for the subscription list.
 *
 * As with the invoice list, nothing here names an account.
 */
final class ListSubscriptionsRequest extends FormRequest
{
    use BoundsPageSize;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', new Enum(SubscriptionStatus::class)],
        ];
    }

    public function status(): ?SubscriptionStatus
    {
        $status = $this->validated()['status'] ?? null;

        return is_string($status) && $status !== '' ? SubscriptionStatus::from($status) : null;
    }
}
