<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Exceptions;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A return of a held paid plan change the platform will not perform
 * (ReturnAHeldPaidChange), or a raw refund that would leave such a change in
 * play (the operator refund route).
 *
 * Operator-facing: raised only on /api/admin routes, answered in English with
 * this message, as every Provisioning refusal is.
 */
final class PaidChangeReturnRefusedException extends DomainException
{
    private string $errorCode = 'provisioning.return_refused';

    public static function becauseTheJobHasNotStopped(string $jobId, ProvisioningJobStatus $status): self
    {
        $exception = new self('Only a job that stopped - in review, or failed - can have its paid change returned. A queued or running job may still deliver it: wait for it to stop.');

        return $exception->withContext(['provisioning_job_id' => $jobId, 'status' => $status->value])
            ->as('provisioning.return_not_stopped');
    }

    public static function becauseItDeliversNoPaidChange(string $jobId): self
    {
        $exception = new self('This job does not deliver a paid plan change: only a resize or a package change queued when a proration invoice was paid, with that change recorded, can be returned this way.');

        return $exception->withContext(['provisioning_job_id' => $jobId])
            ->as('provisioning.return_not_a_paid_change');
    }

    public static function becauseTheServiceHasEnded(string $jobId, ?string $serviceId): self
    {
        $exception = new self('The service this job works for has ended: its paid change is returned by the end itself, and the job is closed instead.');

        return $exception->withContext(['provisioning_job_id' => $jobId, 'service_id' => $serviceId])
            ->as('provisioning.return_service_ended');
    }

    public static function becauseItWasAlreadyReturned(string $jobId, string $planChangeId): self
    {
        $exception = new self('This paid plan change has already been returned.');

        return $exception->withContext(['provisioning_job_id' => $jobId, 'plan_change_id' => $planChangeId])
            ->as('provisioning.return_already_returned');
    }

    public static function becauseTheSubscriptionHasEnded(string $jobId, string $subscriptionId): self
    {
        $exception = new self('The subscription this change was made on has ended: its paid change is returned when the service ends, and the job is then closed.');

        return $exception->withContext(['provisioning_job_id' => $jobId, 'subscription_id' => $subscriptionId])
            ->as('provisioning.return_subscription_ended');
    }

    public static function becauseALaterPaidChangeIsPending(string $jobId, string $laterPlanChangeId): self
    {
        $exception = new self('A later paid plan change on this subscription has not been delivered - it is waiting for its payment, running, or held. Complete or return that one first; this one can then be returned.');

        return $exception->withContext(['provisioning_job_id' => $jobId, 'later_plan_change_id' => $laterPlanChangeId])
            ->as('provisioning.return_a_later_paid_change_is_pending');
    }

    public static function becauseThePlanCannotGoBack(string $jobId, string $planChangeId): self
    {
        $exception = new self('The subscription cannot be put back on the plan this change came from: the change recorded none, or the subscription is no longer on the plan or the price the change moved it to. Nothing was returned.');

        return $exception->withContext(['provisioning_job_id' => $jobId, 'plan_change_id' => $planChangeId])
            ->as('provisioning.return_plan_cannot_go_back');
    }

    public static function becauseARenewalMovedMeanwhile(string $jobId, string $invoiceId): self
    {
        $exception = new self('A renewal of this subscription was issued or paid while the return was being prepared. Nothing was returned: ask again.');

        return $exception->withContext(['provisioning_job_id' => $jobId, 'invoice_id' => $invoiceId])
            ->as('provisioning.return_renewal_moved');
    }

    /**
     * The raw refund route, asked to refund a capture on a proration invoice
     * whose plan change was not delivered
     * (PlanChangeDelivery::aPaidChangeWasNotDelivered()).
     */
    public static function becauseTheChangeItPaidForWasNotDelivered(string $transactionId, string $invoiceId): self
    {
        $exception = new self('This capture paid for a plan change that was not delivered. Its money is returned with the change, not by hand: while the change is held on a live service, return it with POST /api/admin/provisioning/jobs/{job}/return-payment, which also puts the plan back; when the service ends, the end returns it; a later plan change priced from it draws on it.');

        return $exception->withContext(['transaction_id' => $transactionId, 'invoice_id' => $invoiceId])
            ->as('provisioning.refund_of_an_undelivered_paid_change');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 409;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }
}
