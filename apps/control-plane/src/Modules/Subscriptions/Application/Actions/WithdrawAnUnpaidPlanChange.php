<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Actions\ReturnWhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Application\Queries\LockAnInvoiceWhileOpen;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Subscriptions\Application\Listeners\RestorePlanOnVoidedUpgrade;
use Lynomia\Modules\Subscriptions\Application\Queries\UnpaidUpgrade;
use Lynomia\Modules\Subscriptions\Domain\Exceptions\PlanChangeNotWithdrawableException;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * The customer withdraws a plan change they have not paid for (N2 / X7-2,
 * the re-audit after round six).
 *
 * An upgrade moves the subscription the moment it is confirmed and is
 * delivered when its proration invoice is paid. Round six refused that
 * payment once the change could no longer be delivered - the package behind
 * the hosting plan withdrawn, the machine's node filled - and refused
 * nothing else: the invoice stayed open, so every further plan change was
 * refused (`invoice_outstanding`), the subscription stayed on the plan it
 * could not be given and was billed for it, and only an operator's void or
 * the next renewal's lapse ended it. The customer had no way out.
 *
 * This is the way out, and it is the path the lapse and an operator's void
 * already take (RenewSubscription::lapse(), ReturnWhatAnInvoiceStillHolds::
 * andWithdraw()): what the invoice still holds - a part paid from the wallet,
 * say - goes back to the wallet against it, the invoice is voided, and the
 * void puts the subscription back on the plan and the recurring amount the
 * change came from ({@see RestorePlanOnVoidedUpgrade}). Nothing is resized:
 * an upgrade is only delivered on payment. The plan it goes back to has room,
 * because the unit the unpaid upgrade left keeps counting against it
 * (PlanCapacity::claimed()).
 *
 * Any unpaid change can be withdrawn, deliverable or not: one the customer
 * does not pay would lapse at the renewal the same way, and until then it
 * holds every other change of plan. Only while the void would put the plan
 * back (withdrawable()): the invoice is open, it bills the subscription's
 * latest change (UnpaidUpgrade::onto()), the subscription is still on the
 * plan that change moved it to, and it has not ended. A void that restored
 * nothing would leave the subscription on a plan nobody paid for.
 *
 * The lock order WhatAnInvoiceStillHolds writes down, as the renewal's lapse
 * takes it: the invoice (only while it is open, LockAnInvoiceWhileOpen), the
 * subscription, then - inside the return and the void - the wallet and the
 * plan the subscription goes back to.
 */
final readonly class WithdrawAnUnpaidPlanChange
{
    public const string REASON = 'plan_change_withdrawn';

    public function __construct(
        private UnpaidUpgrade $unpaid,
        private ReturnWhatAnInvoiceStillHolds $returnWhatItHolds,
    ) {}

    /**
     * Whether this invoice bills an unpaid plan change the customer can
     * withdraw, as the rows stand. What the invoice resource publishes as
     * `plan_change_withdrawable`; execute() asks it again under the locks.
     */
    public function withdrawable(Invoice $invoice): bool
    {
        if ($invoice->status !== InvoiceStatus::Open || $invoice->order_id !== null || $invoice->subscription_id === null) {
            return false;
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->find($invoice->subscription_id);

        return $subscription !== null && $this->billsTheUnpaidChangeOf($invoice, $subscription);
    }

    /**
     * @return array{invoice: Invoice, returned_to_wallet_minor: int}
     *
     * @throws PlanChangeNotWithdrawableException
     */
    public function execute(Invoice $invoice, ?User $actor = null): array
    {
        return DB::transaction(function () use ($invoice, $actor): array {
            $locked = $invoice->order_id === null && $invoice->subscription_id !== null
                ? LockAnInvoiceWhileOpen::take((string) $invoice->getKey())
                : null;

            if ($locked === null) {
                throw PlanChangeNotWithdrawableException::forInvoice((string) $invoice->getKey());
            }

            /** @var Subscription|null $subscription */
            $subscription = Subscription::query()->lockForUpdate()->find($locked->subscription_id);

            if ($subscription === null || ! $this->billsTheUnpaidChangeOf($locked, $subscription)) {
                throw PlanChangeNotWithdrawableException::forInvoice((string) $invoice->getKey());
            }

            $returned = $this->returnWhatItHolds->andWithdraw(
                $locked,
                'withdrawn-plan-change',
                sprintf('Payment for invoice %s returned: the plan change it billed was withdrawn', $locked->number),
                'The customer withdrew the plan change this invoice billed before paying for it.',
                [
                    'reason' => self::REASON,
                    'subscription_id' => (string) $subscription->getKey(),
                    'withdrawn_by_user_id' => $actor === null ? null : (string) $actor->getKey(),
                ],
            );

            return ['invoice' => $locked->refresh(), 'returned_to_wallet_minor' => $returned];
        });
    }

    /**
     * The invoice bills the subscription's latest change, unpaid, with the
     * subscription still where that change put it and not ended: the only
     * case the void puts the plan back (RestorePlanOnVoidedUpgrade's rule).
     * The change's deliverability is not asked (PlanChangeDelivery): a
     * deliverable change can be withdrawn too.
     */
    private function billsTheUnpaidChangeOf(Invoice $invoice, Subscription $subscription): bool
    {
        if ($subscription->status->isTerminal()) {
            return false;
        }

        $change = $this->unpaid->onto($subscription);

        return $change !== null
            && $change->from_plan_id !== null
            && (string) $change->proration_invoice_id === (string) $invoice->getKey();
    }
}
