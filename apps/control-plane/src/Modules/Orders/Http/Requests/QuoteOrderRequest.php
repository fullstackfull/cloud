<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;

/**
 * The same basket a checkout submits, priced rather than placed.
 *
 * Deliberately the same fields as PlaceOrderRequest, minus the two that only
 * make sense for something that is written: there is no idempotency key,
 * because a quote creates nothing that could be created twice, and no notes,
 * because nobody reads notes on a price.
 */
final class QuoteOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*' => ['required', 'array'],
            'items.*.plan_id' => ['required', 'string', 'ulid', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],

            'billing_period' => ['required', new Enum(BillingPeriod::class)],

            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.plan_id.distinct' => __('validation.requests.order.plan_repeated'),
        ];
    }

    public function toCheckoutRequest(): CheckoutRequest
    {
        $validated = $this->validated();

        $couponCode = isset($validated['coupon_code']) && is_string($validated['coupon_code'])
            ? trim($validated['coupon_code'])
            : null;

        return new CheckoutRequest(
            lines: array_map(
                static fn (array $item): CheckoutLine => new CheckoutLine(
                    planId: $item['plan_id'],
                    quantity: $item['quantity'],
                ),
                array_values($validated['items']),
            ),
            billingPeriod: BillingPeriod::from($validated['billing_period']),
            couponCode: $couponCode === '' ? null : $couponCode,
            // A quote writes nothing, so there is nothing to make idempotent.
            idempotencyKey: null,
            notes: null,
        );
    }
}
