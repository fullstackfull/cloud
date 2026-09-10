<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Concerns\SerialisesMoney;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;

/**
 * A payment as an invoice document reports it.
 *
 * Billing's own view, deliberately not the Payments module's resource: a
 * module may use another module's domain and its records, and may not reach
 * into its HTTP layer. That rule is what stops the API's shapes from becoming
 * a web of mutual dependencies, and the cost here is small — a document needs
 * five facts about a payment, not the payment surface's whole vocabulary.
 *
 * A failed attempt is included on purpose. An invoice that quietly drops a
 * declined card is an invoice whose owner believes they have paid, and the
 * next thing they hear about it is a suspension notice.
 *
 * @mixin Transaction
 */
final class InvoicePaymentResource extends JsonResource
{
    use SerialisesMoney;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // How it was paid: the platform's own wallet, or a provider.
            'provider' => $this->provider,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'is_settled' => $this->status === TransactionStatus::Succeeded,

            'amount' => $this->moneyOfMinor($this->amount_minor, $this->currency),

            /*
             * The provider's code, not its prose: the portal owns the wording,
             * in both languages, and a provider's English sentence shown to an
             * Arabic-speaking customer is not an explanation.
             */
            'failure_code' => $this->failure_code,

            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
