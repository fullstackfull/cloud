<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The client credential a client-side confirmation presents.
 *
 * It is never logged and never persisted: it is a bearer credential for one
 * payment, and it exists in this request and nowhere else.
 */
final class ConfirmControlledPaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_secret' => ['required', 'string', 'min:16', 'max:255'],
        ];
    }

    public function clientSecret(): string
    {
        return (string) $this->validated()['client_secret'];
    }
}
