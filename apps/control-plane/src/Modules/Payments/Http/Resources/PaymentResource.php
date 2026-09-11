<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Concerns\SerialisesMoney;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
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
 *  - failure_message: the provider's own sentence. It is redacted by the
 *    adapter and truncated by the ledger, but it is not composed here — see
 *    StripePaymentProvider, which falls back to the SDK exception's message —
 *    so it is English prose of unknown shape on a page the customer may be
 *    reading in Arabic. No screen ever rendered it.
 *
 * `failure_code` is present, and is the answer to "why was it declined": the
 * stable, provider-normalised reason — `card_declined`, `insufficient_funds` —
 * which the portal renders as a sentence in the reader's own language.
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

            /*
             * Whether this movement was the account paying itself out of its
             * own credit, rather than money arriving from outside.
             *
             * This replaces the gateway's registered driver name, which used
             * to be published here. The name identified a driver — `wallet`,
             * `fake`, whatever the deployment registers — and the portal
             * rendered it through a translation namespace that could not
             * cover an unknown one, so what reached the screen was the slug.
             *
             * The distinction the customer actually needs is this one: "did
             * that nine dinars come off my balance or off my card". It is a
             * fact the platform knows for certain on every row, it names
             * nothing internal, and it cannot acquire a value nobody has
             * written a sentence for.
             */
            'from_account_credit' => $this->provider === IssueRefund::WALLET_PROVIDER,

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

            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
