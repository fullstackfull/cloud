<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Lynomia\Http\Concerns\ReadsIdempotencyKey;

/**
 * The plan a customer wants to move to.
 *
 * The price is named as well as the plan, and both are validated against the
 * catalogue rather than trusted: a plan has one price per currency and period,
 * so the pair has to agree with each other and with the subscription. The
 * action re-checks that the price belongs to the plan, which is the check that
 * actually decides — this one exists so the customer gets a form error instead
 * of a 500.
 *
 * An idempotency key is required, as it is on every other request in this API
 * that moves money. A plan change credits and charges in the same breath, and
 * a customer double-clicking confirm on a slow connection must get one
 * proration rather than two — and one resize rather than two.
 */
final class ChangePlanRequest extends FormRequest
{
    use ReadsIdempotencyKey;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', 'exists:plans,id'],
            'price_id' => ['required', 'string', 'exists:plan_prices,id'],
            // Only for plans sold by the unit. Absent means "keep what I have".
            'units' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
        ] + $this->idempotencyKeyRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->idempotencyKeyMessages();
    }

    public function planId(): string
    {
        return (string) $this->validated('plan_id');
    }

    public function priceId(): string
    {
        return (string) $this->validated('price_id');
    }

    public function units(): ?int
    {
        $units = $this->validated('units');

        return is_int($units) ? $units : null;
    }
}
