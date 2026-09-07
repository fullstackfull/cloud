<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Payments\Http\Resources\Concerns\SerialisesMoney;
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
 * cannot tell the customer why is a support ticket, and both fields are
 * composed for the payer rather than lifted from a provider stack trace.
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

            'amount' => $this->money($this->amount_minor, $this->currency),

            // Which of the account's own invoices this payment is against.
            // Within the acting account by construction: the row was reached
            // through the customer.
            'invoice_id' => $this->invoice_id,

            // The provider that handled it, by the name it is registered
            // under. Useful to a customer reconciling a card statement, and
            // it identifies a driver rather than anything about the account.
            'provider' => $this->provider,

            'is_settled' => $this->status->isFinal(),

            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,

            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
