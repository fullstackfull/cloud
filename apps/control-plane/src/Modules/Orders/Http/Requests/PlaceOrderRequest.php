<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Lynomia\Http\Concerns\ReadsIdempotencyKey;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;

/**
 * A basket, and nothing else.
 *
 * The whole point of this class is what it refuses to read. A checkout that
 * accepts an amount, a currency, a tax rate or a discount from the client is a
 * checkout where the customer sets their own price; a checkout that accepts a
 * customer id is a checkout where they choose whose card is charged. So the
 * accepted fields are plan ids, quantities, a billing period, an optional
 * coupon code and a note — and the rules below are an allow-list, not a filter.
 * The DTO handed to PlaceOrder is built here, field by field, from validated
 * input only; `$request->all()` never reaches it.
 *
 * Money is resolved server-side from the plan's price in the account's own
 * currency, the tax from the account's address, and the discount from the
 * coupon row. None of the three has an input here to override it.
 */
final class PlaceOrderRequest extends FormRequest
{
    /*
     * The idempotency key is taken from the `Idempotency-Key` header and from
     * nowhere else — the same trait every other guarded request uses, so the
     * checkout cannot drift from the power, reinstall and plan-change
     * contracts. Required, not optional-with-a-generated-fallback: a key the
     * server invents is unique per request, which makes every retry a new
     * purchase — precisely the failure the key exists to prevent. Better to
     * refuse the request than to charge twice for it.
     */
    use ReadsIdempotencyKey;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->idempotencyKeyRules() + [
            // Bounded: a basket is a basket, not a bulk import. Each line costs
            // a stock count and a per-customer limit count at checkout.
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*' => ['required', 'array'],
            /*
             * `distinct` because a plan appears in a basket once and its count
             * is the quantity. Two lines naming the same plan are not a richer
             * basket, they are the same purchase split in two — and split in
             * two they are checked against a stock or per-customer limit twice
             * over, each time against a count that does not yet include the
             * other. Refusing the shape here is clearer than reconciling it,
             * and PlaceOrder accumulates per plan as well so a caller that is
             * not this endpoint cannot oversell either.
             */
            'items.*.plan_id' => ['required', 'string', 'ulid', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],

            'billing_period' => ['required', new Enum(BillingPeriod::class)],

            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'An Idempotency-Key header is required so a repeated submission cannot place a second order.',
            'items.*.plan_id.distinct' => 'Each plan may appear in the basket only once; use the quantity to order more than one.',
        ] + $this->idempotencyKeyMessages();
    }

    /**
     * The basket as the application layer wants it.
     *
     * Built from `validated()` rather than from the request, so a field nobody
     * declared above cannot reach PlaceOrder even if a later edit adds it to
     * the payload.
     */
    public function toCheckoutRequest(): CheckoutRequest
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        /** @var list<array{plan_id: string, quantity: int}> $items */
        $items = array_values($validated['items']);

        $couponCode = isset($validated['coupon_code']) && is_string($validated['coupon_code'])
            ? trim($validated['coupon_code'])
            : null;

        return new CheckoutRequest(
            lines: array_map(
                static fn (array $item): CheckoutLine => new CheckoutLine(
                    planId: $item['plan_id'],
                    quantity: $item['quantity'],
                ),
                $items,
            ),
            billingPeriod: BillingPeriod::from($validated['billing_period']),
            couponCode: $couponCode === '' ? null : $couponCode,
            idempotencyKey: $validated['idempotency_key'],
            notes: $validated['notes'] ?? null,
        );
    }
}
