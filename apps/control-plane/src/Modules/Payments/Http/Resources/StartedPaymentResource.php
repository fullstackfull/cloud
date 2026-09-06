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

        $type = match (true) {
            // A provider that has already said no is not waiting for the
            // browser to do anything, and handing out a bearer credential for
            // a payment that cannot proceed is a credential for nothing.
            $intent->status->isFinal() && ! $intent->succeeded() => 'none',
            $intent->nextActionUrl !== null => 'redirect',
            $intent->clientSecret !== null => 'client_secret',
            default => 'none',
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
                'client_secret' => $type === 'client_secret' ? $intent->clientSecret : null,
            ],

            'failure_code' => $intent->failureCode,
            'failure_message' => $intent->failureMessage,
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
