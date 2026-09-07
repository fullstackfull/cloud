<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Why the customer is withdrawing the order.
 *
 * Optional, and recorded on the transition rather than acted on: an order is
 * cancellable or it is not, and no reason a client sends changes that answer.
 */
final class CancelOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->validated()['reason'] ?? null;

        if (! is_string($reason)) {
            return null;
        }

        $reason = trim($reason);

        return $reason === '' ? null : $reason;
    }
}
