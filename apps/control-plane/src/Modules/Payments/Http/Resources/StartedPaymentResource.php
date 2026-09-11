<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Payments\Application\DTOs\StartedPayment;

/**
 * The answer to "I want to pay this invoice": the pending payment, and what
 * the browser has to do next.
 *
 * `next_action` is the whole point of the endpoint. A provider either needs
 * the customer sent somewhere (`redirect`) or needs a credential handed to its
 * own client-side SDK (`client_secret`); naming which one is required means a
 * client does not have to guess from which field happens to be null.
 *
 * What this resource does NOT say is whether the payment worked. `status`
 * here is the provider's opinion of an intent that nobody has confirmed, and a
 * client must not read it as settlement — the invoice is settled by a webhook,
 * and `payment.status` on the next read of the payment is where that shows up.
 *
 * The client secret appears here and nowhere else: it is a bearer credential
 * for this one payment, it is never persisted, and it is served only to the
 * request that created the intent.
 *
 * @mixin StartedPayment
 */
final class StartedPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StartedPayment $started */
        $started = $this->resource;
        $intent = $started->intent;

        /*
         * Five answers, and each one is a different screen.
         *
         * The vocabulary used to be `none | redirect | client_secret`, which
         * collapsed three unlike situations into "none": a declined card, a
         * payment that already went through, and a payment the provider has
         * not made its mind up about. A portal cannot tell those apart, so it
         * showed the same nothing for all three — and the most dangerous of
         * them is the third, where the right thing to say is "we are checking,
         * do not pay again" and the wrong thing is a retry button.
         *
         *   redirect             send the customer to the provider's page
         *   client_confirmation  confirm here, with the client credential
         *   completed            the provider has already taken it
         *   failed               the provider refused it, and says why
         *   pending              nobody knows yet; wait, do not retry
         */
        $type = match (true) {
            $intent->succeeded() => 'completed',
            $intent->status->isFinal() => 'failed',
            $intent->nextActionUrl !== null => 'redirect',
            $intent->clientSecret !== null => 'client_confirmation',
            default => 'pending',
        };

        return [
            'payment' => new PaymentResource($started->transaction),

            'provider' => $started->provider,
            // The provider's own identifier for this intent, which its
            // client-side SDK needs in order to confirm it.
            'reference' => $intent->reference,
            'status' => $intent->status->value,

            'next_action' => [
                'type' => $type,
                'redirect_url' => $type === 'redirect' ? $intent->nextActionUrl : null,
                'client_secret' => $type === 'client_confirmation' ? $intent->clientSecret : null,

                /*
                 * Whether a client may ask again in a moment.
                 *
                 * True only for `pending`, and it is the one branch where a
                 * client must NOT start a second payment: the money may
                 * already be moving. Everything else is either finished or
                 * waiting on the customer.
                 */
                'is_awaiting_provider' => $type === 'pending',
            ],

            'failure_code' => $intent->failureCode,
            /*
             * The provider's own sentence is deliberately not here.
             *
             * It used to be, with a comment arguing a refused payment that
             * cannot say why is a support ticket — and the argument was right
             * about the need and wrong about the field. What was published was
             * the gateway's or the registrar's English prose, falling back to
             * an SDK exception message, which reached an Arabic customer in
             * English and was never rendered by any screen. `failure_code` is
             * the bounded, normalised reason, it is what the portal branches
             * on, and it is the half that can be said in the reader's own
             * language.
             */

        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        /** @var StartedPayment $started */
        $started = $this->resource;

        return [
            'meta' => [
                'invoice_id' => $started->transaction->invoice_id,
                'attempt_number' => $started->attempt->attempt_number,
                /*
                 * Stated in the payload because it is the single most
                 * misimplemented thing about a checkout: a client that
                 * provisions on this response provisions on a claim. Nothing
                 * is delivered until the provider tells the platform, server
                 * to server, that the money arrived.
                 */
                'settlement' => 'This payment is not settled. The invoice is marked paid only after the provider confirms the capture to the platform.',
            ],
        ];
    }
}
