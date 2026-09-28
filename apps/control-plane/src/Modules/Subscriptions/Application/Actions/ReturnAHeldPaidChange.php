<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Billing\Application\Queries\LockAnInvoiceWhileOpen;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Provisioning\Application\Actions\RetryProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Exceptions\PaidChangeReturnRefusedException;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Application\Queries\PlanChangeDelivery;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use RuntimeException;

/**
 * An operator returns a paid plan change held on a live service, and takes
 * it out of play.
 *
 * A paid upgrade whose resize or package change stopped after its
 * settlement - in review, or failed outright - is held for an operator while
 * the service lives: completed by a retry, or returned (docs/billing.md). The
 * return used to be the raw refund of the capture
 * (`POST /api/admin/transactions/{transaction}/refunds`), which moved the
 * money and left the change in play (B1, the verification of round ten M):
 * the subscription on the new plan and billed at it, a job in review holding
 * every plan change (`service_busy`), a downgrade crediting the refunded
 * upgrade a second time, and a retry able to deliver a change already paid
 * back. The raw refund of an undelivered paid change is refused now
 * (PlanChangeDelivery::aPaidChangeWasNotDelivered()), and this is the return.
 *
 * It accepts a resize or a package change queued under a paid proration
 * invoice's key, in review or failed, whose change is recorded and not yet
 * returned, on a service that has not ended and a subscription that has not
 * ended - an ended one's paid change is returned by the end, and its job is
 * closed (CloseAJobWhoseServiceEnded). Anything else is refused
 * (PaidChangeReturnRefusedException, 409). What it does depends on whether
 * the plan was changed again after this change:
 *
 *  - Not changed again (no later change, or only later changes that were
 *    returned themselves). The change is returned in full:
 *     - what the invoice still holds goes back to the wallet, against the
 *       invoice (ReturnWhatAnInvoiceStillHolds);
 *     - the subscription goes back to the plan and recurring amount the
 *       change came from (ReturnAPlanChangeNoLongerDeliverable::returnHeld());
 *     - every renewal issued since the change at its price is priced again
 *       at the old one (RepriceTheRenewalsAReturnedChangeBilled): an open
 *       renewal is withdrawn - what was paid on it back to the wallet - and
 *       issued again; a paid one has the difference returned to the wallet
 *       against it. A renewal issued at 90.000 while the change was held used
 *       to stay open at 90.000, or at 17.000 owing after a 73.000 wallet
 *       payment, for a period on the small plan (N1, the verification of
 *       round ten M, 1af8ec1);
 *     - the customer is told (`billing.held_plan_change_returned`).
 *    A change whose plan cannot be put back - none recorded, or the
 *    subscription not on the plan the change moved it to - is refused
 *    (`provisioning.return_plan_cannot_go_back`).
 *  - Changed again, by a later change that was settled - it owed nothing, or
 *    its invoice was paid and it was delivered. Nothing is credited: the
 *    later change was priced from the plan this change moved onto, and drew
 *    on this change's invoice for its credit, or charged only the difference
 *    from it - what this change's invoice still holds is what the plan the
 *    subscription is on now still consumes. The change is recorded returned,
 *    the job is taken out of play, and the customer is told
 *    (`billing.held_plan_change_returned_plan_kept`). It used to credit what
 *    the invoice held as well, and a later move back to the small plan then
 *    credited the same money again - 109.000 against 100.000 (O1, the same
 *    verification). What the invoice holds stays against it: it is returned
 *    at the service's end if nothing later was delivered
 *    (ReturnAnUpgradeTheEndPrevented), drawn on by a later change's credit,
 *    and not refundable by hand.
 *  - Changed again by a later paid change not yet delivered - awaiting its
 *    payment, queued, held - refused (`provisioning.return_a_later_paid_change_is_pending`):
 *    returning this one first would leave that one's plan resting on a
 *    change nothing will deliver. Return or complete the later one first;
 *    this one is then "not changed again".
 *
 * Either way the job is cancelled (`needs_review` or `failed` ->
 * `cancelled`) and the return recorded on its result (RETURNED): it leaves
 * the review list, holds no plan change, and cannot be retried
 * ({@see RetryProvisioningJob} refuses a job that is not failed or in
 * review). The operator's act is audited by the route, with the evidence.
 *
 * Locks, in the money-path order (WhatAnInvoiceStillHolds): the job; the
 * open renewals being repriced (LockAnInvoiceWhileOpen, before the
 * subscription, as the renewal and the wind-up take an open invoice); the
 * subscription; the paid invoices - this change's and the paid renewals -
 * in ascending id order, after the subscription, as the settlement and
 * ApplyPlanChange take a paid invoice; then, inside the return, the wallet
 * and the plan the subscription goes back to. No provider is called.
 */
final readonly class ReturnAHeldPaidChange
{
    /** Where on the job's result the return is recorded. */
    public const string RETURNED = 'paid_change_returned_by_an_operator';

    /** The audit reason of a held change taken out of play with nothing to return. */
    public const string OUT_OF_PLAY_AUDIT_REASON = 'held_plan_change_taken_out_of_play_by_an_operator';

    public function __construct(
        private ReturnAPlanChangeNoLongerDeliverable $returnIt,
        private RepriceTheRenewalsAReturnedChangeBilled $reprice,
        private PlanChangeDelivery $delivery,
        private ProvisioningJobStateMachine $jobStates,
        private RecordAuditEntry $audit,
        private NotifyCustomer $notify,
    ) {}

    /**
     * @return array{job: ProvisioningJob, credited: int, restored: bool, renewals_reissued: list<string>}
     *
     * @throws PaidChangeReturnRefusedException
     */
    public function execute(ProvisioningJob $job, string $returnedBy): array
    {
        return DB::transaction(function () use ($job, $returnedBy): array {
            /** @var ProvisioningJob $locked */
            $locked = ProvisioningJob::query()->lockForUpdate()->findOrFail($job->getKey());
            $jobId = (string) $locked->getKey();

            if ($locked->status !== ProvisioningJobStatus::NeedsReview && $locked->status !== ProvisioningJobStatus::Failed) {
                throw PaidChangeReturnRefusedException::becauseTheJobHasNotStopped($jobId, $locked->status);
            }

            $delivers = $this->whatItDelivers($locked);

            if ($delivers === null) {
                throw PaidChangeReturnRefusedException::becauseItDeliversNoPaidChange($jobId);
            }

            [$subscriptionId, $invoiceId] = $delivers;

            $service = $locked->service_id === null ? null : Service::query()->find($locked->service_id);

            if ($service === null || $service->status === ServiceStatus::Terminated) {
                throw PaidChangeReturnRefusedException::becauseTheServiceHasEnded($jobId, $locked->service_id);
            }

            /** @var PlanChange|null $change */
            $change = PlanChange::query()->where('proration_invoice_id', $invoiceId)->first();

            if ($change === null || (string) $change->subscription_id !== $subscriptionId) {
                throw PaidChangeReturnRefusedException::becauseItDeliversNoPaidChange($jobId);
            }

            if ($change->returned_at !== null) {
                throw PaidChangeReturnRefusedException::becauseItWasAlreadyReturned($jobId, (string) $change->getKey());
            }

            $later = $this->laterChanges($change);

            foreach ($later as $candidate) {
                if ($candidate->proration_invoice_id !== null && ! $this->wasPaidAndDelivered($candidate)) {
                    throw PaidChangeReturnRefusedException::becauseALaterPaidChangeIsPending($jobId, (string) $candidate->getKey());
                }
            }

            /** @var Subscription|null $unlockedSubscription */
            $unlockedSubscription = Subscription::query()->find($subscriptionId);

            if ($unlockedSubscription === null) {
                throw PaidChangeReturnRefusedException::becauseItDeliversNoPaidChange($jobId);
            }

            // What the change billed its renewals at: the price it moved the
            // subscription to, which the subscription still carries while
            // nothing was changed after it.
            $billedAt = (int) $unlockedSubscription->recurring_amount_minor;
            $returnInFull = $later === [];

            // Open renewals first, before the subscription (the class docblock).
            $openRenewals = [];

            if ($returnInFull) {
                foreach (RepriceTheRenewalsAReturnedChangeBilled::renewalsToReprice($unlockedSubscription, $change, $billedAt) as $renewalId) {
                    $openLocked = LockAnInvoiceWhileOpen::take($renewalId);

                    if ($openLocked !== null) {
                        $openRenewals[$renewalId] = $openLocked;
                    }
                }
            }

            /** @var Subscription $subscription */
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscriptionId);

            if ($subscription->status->isTerminal()) {
                throw PaidChangeReturnRefusedException::becauseTheSubscriptionHasEnded($jobId, $subscriptionId);
            }

            if ($returnInFull
                && ($change->from_plan_id === null
                    || $change->from_recurring_amount_minor === null
                    || $subscription->plan_id !== $change->to_plan_id
                    || (int) $subscription->recurring_amount_minor !== $billedAt)) {
                throw PaidChangeReturnRefusedException::becauseThePlanCannotGoBack($jobId, (string) $change->getKey());
            }

            // The paid invoices, after the subscription, in ascending id order.
            $renewalIds = $returnInFull ? RepriceTheRenewalsAReturnedChangeBilled::renewalsToReprice($subscription, $change, $billedAt) : [];
            $paidIds = [$invoiceId];

            foreach ($renewalIds as $renewalId) {
                if (! isset($openRenewals[$renewalId])) {
                    $paidIds[] = $renewalId;
                }
            }

            sort($paidIds);

            /** @var array<string, Invoice> $paid */
            $paid = [];

            foreach ($paidIds as $paidId) {
                /** @var Invoice|null $row */
                $row = Invoice::query()->lockForUpdate()->find($paidId);

                if ($row === null) {
                    continue;
                }

                if ($paidId !== $invoiceId && $row->status !== InvoiceStatus::Paid) {
                    // A renewal issued, or reopened, between the read and the
                    // subscription's lock: not locked before the subscription,
                    // so not touched here. Asked again, it is found first.
                    throw PaidChangeReturnRefusedException::becauseARenewalMovedMeanwhile($jobId, $paidId);
                }

                $paid[$paidId] = $row;
            }

            $invoice = $paid[$invoiceId] ?? null;

            if ($invoice === null || $invoice->status !== InvoiceStatus::Paid || (string) $invoice->subscription_id !== $subscriptionId) {
                throw PaidChangeReturnRefusedException::becauseItDeliversNoPaidChange($jobId);
            }

            if ($returnInFull) {
                $outcome = $this->returnIt->returnHeld(
                    $subscription,
                    $change,
                    $invoice,
                    sprintf('its %s stopped (%s) on a live service, and an operator returned it', $locked->kind->value, $locked->status->value),
                );

                if (! $outcome['restored']) {
                    throw new RuntimeException('A held paid change was returned and its plan did not go back; the return is rolled back.');
                }

                $renewals = [];

                foreach ($renewalIds as $renewalId) {
                    $renewals[] = $openRenewals[$renewalId] ?? $paid[$renewalId];
                }

                $repriced = $this->reprice->execute($subscription, $change, $renewals, $billedAt);
                $credited = $outcome['credited'];
                $restored = true;
                $reissued = $repriced['reissued'];
            } else {
                $this->takeOutOfPlay($subscription, $change, $invoice);
                $credited = 0;
                $restored = false;
                $reissued = [];
            }

            $this->jobStates->assertCanTransition($locked->status, ProvisioningJobStatus::Cancelled);

            $locked->status = ProvisioningJobStatus::Cancelled;
            $locked->finished_at = now();
            // The error is left where it is, as a close leaves it: it is what
            // the job was stopped for.
            $locked->result = [
                ...($locked->result ?? []),
                self::RETURNED => [
                    'by' => $returnedBy,
                    'at' => now()->toIso8601String(),
                    'invoice_id' => $invoiceId,
                    'returned_to_wallet_minor' => $credited,
                    'plan_restored' => $restored,
                    'renewals_reissued' => $reissued,
                ],
            ];
            $locked->save();

            return ['job' => $locked, 'credited' => $credited, 'restored' => $restored, 'renewals_reissued' => $reissued];
        });
    }

    /**
     * A change the plan moved on from: nothing is credited (the class
     * docblock), the change is recorded returned so it is not returned or
     * taken out of play twice, audited, and the customer told.
     */
    private function takeOutOfPlay(Subscription $subscription, PlanChange $change, Invoice $invoice): void
    {
        PlanChange::query()
            ->whereKey($change->getKey())
            ->whereNull('returned_at')
            ->update([
                'returned_at' => now(),
                'return_reason' => 'taken out of play by an operator with nothing returned: a later plan change was settled from it',
            ]);

        $this->audit->execute(
            action: AuditAction::PlanChanged,
            subject: $subscription,
            customerId: $subscription->customer_id,
            context: [
                'from_plan_id' => $subscription->plan_id,
                'to_plan_id' => $subscription->plan_id,
                'reason' => self::OUT_OF_PLAY_AUDIT_REASON,
                'plan_restored' => false,
                'proration_invoice_id' => (string) $invoice->getKey(),
                'plan_change_id' => (string) $change->getKey(),
                'returned_to_wallet_minor' => 0,
            ],
        );

        $service = Service::query()->where('subscription_id', $subscription->getKey())->first();
        $label = $service?->label;
        $name = $service === null
            ? ['en' => 'your service', 'ar' => 'خدمتك']
            : (is_string($label) && $label !== '' ? $label : (string) $service->getKey());

        DB::afterCommit(function () use ($subscription, $change, $name): void {
            $this->notify->execute(
                customerId: (string) $subscription->customer_id,
                type: NotificationType::HeldPlanChangeReturnedPlanKept,
                idempotencyKey: 'plan-change-returned:'.$change->getKey(),
                subject: $subscription,
                data: ['service' => $name],
                link: '/subscriptions',
            );
        });
    }

    /**
     * The changes of this subscription recorded after this one and not
     * returned themselves, oldest first.
     *
     * @return list<PlanChange>
     */
    private function laterChanges(PlanChange $change): array
    {
        return PlanChange::query()
            ->where('subscription_id', $change->subscription_id)
            ->whereNull('returned_at')
            ->where(static fn ($query) => $query
                ->where('changed_at', '>', $change->changed_at)
                ->orWhere(static fn ($same) => $same
                    ->where('changed_at', $change->changed_at)
                    ->where('id', '>', $change->id)))
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function wasPaidAndDelivered(PlanChange $change): bool
    {
        $paid = Invoice::query()
            ->whereKey($change->proration_invoice_id)
            ->where('status', InvoiceStatus::Paid->value)
            ->exists();

        return $paid && $this->delivery->wasDeliveredWhileLive((string) $change->subscription_id, (string) $change->proration_invoice_id);
    }

    /**
     * The subscription and the proration invoice this job delivers, read off
     * its key (`plan-change:<subscription>:<plan>:invoice:<id>`,
     * QueuePlanChangeAtProvider); null for anything else.
     *
     * @return array{string, string}|null
     */
    private function whatItDelivers(ProvisioningJob $job): ?array
    {
        if ($job->kind !== ProvisioningJobKind::Resize && $job->kind !== ProvisioningJobKind::ChangeHostingPackage) {
            return null;
        }

        $key = (string) $job->idempotency_key;

        if (preg_match('/\Aplan-change:([^:]+):[^:]+:invoice:([0-9A-Za-z]{26})\z/', $key, $match) !== 1) {
            return null;
        }

        return [$match[1], $match[2]];
    }
}
