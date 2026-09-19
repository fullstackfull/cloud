<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Concerns\SerialisesMoney;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Application\DTOs\PricedCheckout;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * What a basket will cost, itemised, before anything is bought.
 *
 * Every figure here is the server's, produced by the same engine that prices
 * the order and issues the invoice, and every one of them is published as
 * minor units plus a currency. The portal formats them and does not add them
 * up: a browser that computes a total is a browser that can disagree with the
 * invoice, and the customer will believe the browser.
 *
 * The renewal figure is separated from the total on purpose, because they
 * answer two different questions — "what do I pay now" includes the setup fee
 * and any coupon, "what do I pay next time" includes neither.
 *
 * @mixin PricedCheckout
 */
final class OrderQuoteResource extends JsonResource
{
    use SerialisesMoney;

    public function __construct(PricedCheckout $resource, private readonly CheckoutRequest $basket)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PricedCheckout $checkout */
        $checkout = $this->resource;
        $priced = $checkout->priced;
        $currency = $priced->currency();

        return [
            'currency' => $currency,
            'billing_period' => $this->basket->billingPeriod->value,

            'lines' => array_map(
                function (PricingLine $line, int $index) use ($checkout): array {
                    $total = $checkout->priced->lines[$index];
                    $planId = $this->basket->lines[$index]->planId;

                    return [
                        'description' => $line->description,
                        'plan_id' => $planId,
                        'quantity' => $line->quantity,

                        'unit_recurring' => $this->money($line->unitPrice),
                        'unit_setup' => $this->money($line->setupFee),

                        // The line as priced: gross, what the coupon took off
                        // it, the tax on the remainder, and the total.
                        'gross' => $this->money($total->gross),
                        'discount' => $this->money($total->discount),
                        'tax' => $this->money($total->tax),
                        'total' => $this->money($total->total),

                        'tax_rate' => $total->taxRate,
                        'tax_name' => $total->taxName,
                    ];
                },
                $checkout->pricingLines,
                array_keys($checkout->pricingLines),
            ),

            /*
             * The one-off part of this basket, named separately because it is
             * the figure a customer is most often surprised by: a setup fee
             * folded silently into a total reads as the platform having got
             * the price wrong.
             */
            'setup' => $this->money(array_reduce(
                $checkout->pricingLines,
                fn (Money $carry, PricingLine $line): Money => $carry->plus($line->setupFee),
                Money::zero($currency),
            )),

            'subtotal' => $this->money($priced->subtotal),
            'discount' => $this->money($priced->discount),
            'tax' => $this->money($priced->tax),
            'total' => $this->money($priced->total),

            'coupon_code' => $priced->couponCode,

            'tax_rate' => $checkout->taxRate->rate,
            'tax_name' => $checkout->taxRate->name,

            /*
             * What the same lines cost when the period comes round again,
             * without the setup fee and without the coupon. It is priced by
             * the same engine against today's catalogue and today's tax rule,
             * which is why it is labelled a projection and not a promise.
             */
            'renewal' => [
                'billing_period' => $this->basket->billingPeriod->value,
                'subtotal' => $this->money($checkout->renewal->subtotal),
                'tax' => $this->money($checkout->renewal->tax),
                'total' => $this->money($checkout->renewal->total),
                'includes_setup' => false,
                'includes_coupon' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                /*
                 * Said out loud, because the difference matters: this priced
                 * nothing into existence. No order was created, no stock was
                 * taken, no coupon use was held, and nothing is owed. The
                 * customer owes money when they place the order and the
                 * platform issues an invoice for it.
                 */
                'is_quote' => true,
                'creates_nothing' => true,
                'after_payment' => 'The order is placed, an invoice is issued for it, and provisioning starts once the payment is confirmed by the provider.',
            ],
        ];
    }
}
