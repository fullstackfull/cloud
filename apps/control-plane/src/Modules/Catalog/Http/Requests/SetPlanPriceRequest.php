<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Billing\Domain\Services\BillingCurrencies;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;

/**
 * What an operator must say to price a plan.
 *
 * Both amounts are `integer`, and that rule is doing real work: Laravel's
 * `integer` refuses `"9.000"` and `9.5` rather than casting them, so a screen
 * that sent a decimal is told so instead of having it rounded on the way in.
 * The currency list is not restated here — the action asks
 * {@see BillingCurrencies}, which is
 * the same list registration and every other currency-bearing request use, so
 * enabling a currency is one edit rather than a search for copies.
 */
final class SetPlanPriceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'billing_period' => ['required', Rule::enum(BillingPeriod::class)],

            'recurring_amount_minor' => ['required', 'integer', 'min:0'],
            'setup_amount_minor' => ['nullable', 'integer', 'min:0'],

            'is_active' => ['required', 'boolean'],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date'],
        ];
    }
}
