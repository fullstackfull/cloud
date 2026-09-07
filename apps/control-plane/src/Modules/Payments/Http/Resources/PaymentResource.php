<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Concerns\SerialisesMoney;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;

/**
 * What a customer may see of one movement of money on their account.
 *
 * Absent on purpose, and each for its own reason:
 *
 *  - customer_id: the caller already knows which account they are acting for,
 *    and the id buys them nothing but a shape to probe with.
 *  - provider_reference: a bearer-shaped identifier for the payment at the
 *    provider. The payer holds the one for the payment they are in the middle
 *    of, because their browser needs it; printing every historical one on a
 *    list widens where it can leak from — into a screenshot, a shared link, a
 *    support ticket — for no gain the customer can use.
 *  - provider_metadata: the provider's own response object. It is kept for
 *    operators investigating a chargeback, it changes shape whenever the
 *    provider feels like it, and nothing in it is a promise to a customer.
 *
 * `failure_code` and `failure_message` are present: a declined payment that
 * cannot tell the customer why is a support ticket. `failure_code` is the
 * stable, provider-normalised reason — `card_declined`, `insufficient_funds` —
 * and is the field a client should branch on. `failure_message` is the
 * provider's own sentence, redacted by the adapter and truncated by the ledger
 * but not composed here, so it is a hint for a human and never a contract; see
 * StripePaymentProvider, which falls back to the SDK exception's message.
 *
 * @mixin Transaction
 */
final class PaymentResource extends JsonResource
{
    use SerialisesMoney;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,

            'amount' => $this->moneyOfMinor($this->amount_minor, $this->currency),

            // Which of the account's own invoices this payment is against.
            // Within the acting account by construction: the row was reached
            // through the customer.
            'invoice_id' => $this->invoice_id,

            // The provider that handled it, by the name it is registered
            // under. Useful to a customer reconciling a card statement, and
            // it identifies a driver rather than anything about the account.
            'provider' => $this->provider,

            /*
             * Settled means the money arrived, not that the provider has
             * stopped talking. A declined charge is finished but it is not
             * settled, and reporting it as settled — the only boolean in this
             * payload, and so the one a client reaches for — is how a
             * dashboard shows a paid invoice for a payment that never landed.
             * `status` is where "is it still in flight?" is answered.
             */
            'is_settled' => $this->status === TransactionStatus::Succeeded,

            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,

            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
