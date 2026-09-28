<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Provisioning\Application\Actions\RetryProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Exceptions\PaidChangeReturnRefusedException;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * An operator returns a paid plan change held on a live service, and takes
 * it out of play.
 *
 * A paid upgrade whose resize or package change stopped after its
 * settlement - in review, or failed outright - is held for an operator while
 * the service lives: completed by a retry, or returned (docs/billing.md). The
 * return used to be the raw refund of the capture
 * (`POST /api/admin/transactions/{transaction}/refunds`), which moved the
 * money and nothing else, and left the change in play (B1, the verification
 * of round ten M):
 *
 *  - the subscription stayed on the new plan and its price, so the renewal
 *    billed 90.000 for a machine still at the small plan's shape;
 *  - a job in review held every plan change (`service_busy`), so the
 *    customer could not even move back, and a close is refused on a live
 *    service;
 *  - after a failed job the customer could move back, and the downgrade's
 *    credit counted the refunded upgrade as paid a second time - 12.000
 *    credited against 3.000 charged, 9.000 over;
 *  - and a retry could still deliver a change already paid back.
 *
 * So this is the return, in one transaction, and the raw refund is refused
 * while a paid change is in play (PlanChangeDelivery::aPaidChangeIsInPlay()):
 *
 *  - the job must be a resize or a package change queued under a paid
 *    proration invoice's key, in review or failed, on a service that has not
 *    ended (an ended service's paid change is returned by the end, and its
 *    job is closed: CloseAJobWhoseServiceEnded), with its change recorded
 *    and not yet returned; anything else is refused
 *    (PaidChangeReturnRefusedException, 409);
 *  - what the invoice still holds goes back to the wallet, against the
 *    invoice, through ReturnWhatAnInvoiceStillHolds; the subscription goes
 *    back to the plan and recurring amount the change came from when nothing
 *    was changed after it and it has not ended; the change is stamped
 *    `returned_at`; an audit entry is written and the customer told
 *    (ReturnAPlanChangeNoLongerDeliverable::returnHeld());
 *  - the job is cancelled (`needs_review` or `failed` -> `cancelled`) and
 *    the return recorded on its result (RETURNED), so it leaves the review
 *    list, holds no plan change, and cannot be retried
 *    ({@see RetryProvisioningJob} refuses a job that is not failed or in
 *    review).
 *
 * The money goes to the wallet, as every return of an undelivered purchase
 * does; a card refund on top of it stays an operator's decision, held to
 * what the invoice then holds - nothing.
 *
 * Locks: the job, then the subscription, then the paid invoice, then -
 * inside the return - the wallet and the plan it goes back to. A paid
 * invoice after its subscription, as the settlement and ApplyPlanChange take
 * one (WhatAnInvoiceStillHolds, the lock order, says why that is safe); the
 * job before both, as the close takes it. No provider is called.
 *
 * Before returning a resize in review, the runbook has the operator look at
 * the machine: one stopped as `vps.resize_unverified` may have grown, and a
 * retry settles that instead (docs/runbooks/provisioning-stuck.md §6).
 */
final readonly class ReturnAHeldPaidChange
{
    /** Where on the job's result the return is recorded. */
    public const string RETURNED = 'paid_change_returned_by_an_operator';

    public function __construct(
        private ReturnAPlanChangeNoLongerDeliverable $returnIt,
        private ProvisioningJobStateMachine $jobStates,
    ) {}

    /**
     * @return array{job: ProvisioningJob, credited: int, restored: bool}
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

            /*
             * The subscription before the paid invoice: the order the
             * settlement and ApplyPlanChange take a paid invoice in, and the
             * one WhatAnInvoiceStillHolds declares safe for it, because
             * nothing holding a paid invoice's lock waits for a subscription.
             * Locking the invoice first and then waiting for the subscription
             * would break that, and meet a downgrade holding the subscription
             * and waiting for this invoice.
             */
            /** @var Subscription|null $subscription */
            $subscription = Subscription::query()->lockForUpdate()->find($subscriptionId);

            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);

            /** @var PlanChange|null $change */
            $change = PlanChange::query()->where('proration_invoice_id', $invoiceId)->first();

            if ($subscription === null
                || $invoice === null
                || $invoice->status !== InvoiceStatus::Paid
                || (string) $invoice->subscription_id !== $subscriptionId
                || $change === null) {
                throw PaidChangeReturnRefusedException::becauseItDeliversNoPaidChange($jobId);
            }

            if ($change->returned_at !== null) {
                throw PaidChangeReturnRefusedException::becauseItWasAlreadyReturned($jobId, (string) $change->getKey());
            }

            $outcome = $this->returnIt->returnHeld(
                $subscription,
                $change,
                $invoice,
                sprintf('its %s stopped (%s) on a live service, and an operator returned it', $locked->kind->value, $locked->status->value),
            );

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
                    'returned_to_wallet_minor' => $outcome['credited'],
                    'plan_restored' => $outcome['restored'],
                ],
            ];
            $locked->save();

            return ['job' => $locked, 'credited' => $outcome['credited'], 'restored' => $outcome['restored']];
        });
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
