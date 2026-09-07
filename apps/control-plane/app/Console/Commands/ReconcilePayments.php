<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Payments\Application\Actions\ConfirmPaymentFromReturn;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Throwable;

/**
 * Asks the provider about payments the platform never heard back about.
 *
 * ---------------------------------------------------------------------------
 * Why this is a sweep and not an endpoint
 * ---------------------------------------------------------------------------
 *
 * ConfirmPaymentFromReturn is named for the browser redirect, and the obvious
 * home for it is a `POST /payments/confirm` the portal calls when the customer
 * comes back. The platform forbids that, structurally: no route under `api/`
 * may look like a customer-facing payment confirmation, and a test enforces
 * it. The reasoning is not that any particular endpoint would trust the
 * client — this action pointedly does not — but that a rule which has to be
 * re-audited per endpoint is a rule that eventually loses.
 *
 * A sweep is strictly stronger anyway, on the merits:
 *
 *  - it takes no input from anybody. The provider name and the reference come
 *    from the platform's own transaction row, so the bearer-shaped reference
 *    never has to travel through a request at all;
 *  - it does not depend on the customer coming back. The hole being closed is
 *    a lost webhook, and a browser that closed the tab is exactly the case
 *    where nobody returns to trigger a confirmation;
 *  - it covers payments started from anywhere, including the API and a retry
 *    on another device.
 *
 * What it is not is a way to settle a payment the provider has not settled.
 * The action asks and believes the answer; nothing here can say "succeeded".
 *
 * ---------------------------------------------------------------------------
 * The delay
 * ---------------------------------------------------------------------------
 *
 * Attempts younger than the grace period are left alone. A payment that is
 * seconds old is normally mid-flight — the customer is still on the provider's
 * page — and the webhook is the faster path when it works. This is the
 * backstop, and a backstop that races the primary path just doubles the
 * provider calls for every payment the platform takes.
 */
final class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile
        {--older-than=15 : Only look at attempts at least this many minutes old}
        {--limit=200 : How many attempts to ask about in one pass}';

    protected $description = 'Ask providers about payments that never produced a webhook';

    public function handle(ConfirmPaymentFromReturn $confirm): int
    {
        $pending = Transaction::query()
            ->where('status', TransactionStatus::Pending->value)
            ->whereNotNull('provider_reference')
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('older-than')))
            // Oldest first: an attempt nobody has heard about for a day is
            // more urgent than one from twenty minutes ago, and a pass that
            // cannot finish still makes progress through the backlog.
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $settled = 0;
        $unchanged = 0;
        $failed = 0;

        foreach ($pending as $attempt) {
            try {
                $result = $confirm->execute(
                    providerName: $attempt->provider,
                    reference: (string) $attempt->provider_reference,
                    customerId: $attempt->customer_id,
                    invoiceId: $attempt->invoice_id,
                );

                $result === null ? $unchanged++ : $settled++;
            } catch (Throwable $e) {
                /*
                 * Counted and carried past. One provider refusing, or one row
                 * whose reference the provider no longer recognises, must not
                 * stop the platform asking about everybody else's money.
                 */
                $failed++;

                $this->components->warn(sprintf(
                    'Could not reconcile payment %s: %s',
                    $attempt->getKey(),
                    $e->getMessage(),
                ));
            }
        }

        $this->line((string) json_encode([
            'command' => 'payments:reconcile',
            'considered' => $pending->count(),
            'settled' => $settled,
            'still_pending' => $unchanged,
            'failed' => $failed,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
