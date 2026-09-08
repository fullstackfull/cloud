<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Lynomia\Http\Concerns\ReadsIdempotencyKey;

/**
 * A key, and nothing else.
 *
 * There is deliberately no `amount`. What a wallet payment applies is decided
 * by the platform from the balance and the amount due, both read under a lock;
 * an amount from the client would be a second opinion about a figure that has
 * exactly one right answer, and the only thing it could do is disagree.
 */
final class PayInvoiceFromCreditRequest extends FormRequest
{
    use ReadsIdempotencyKey;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->idempotencyKeyRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->idempotencyKeyMessages();
    }
}
