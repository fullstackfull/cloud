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

    /**
     * The raw refund route, asked to refund a capture on a proration invoice
     * whose plan change is still in play (PlanChangeDelivery::aPaidChangeIsInPlay()).
     */
    public static function becauseARefundWouldLeaveTheChangeInPlay(string $transactionId, string $invoiceId): self
    {
        $exception = new self('This capture paid for a plan change that has not been delivered and is still in play. A refund would leave the subscription on the new plan and a retry able to deliver it: when the job has stopped, return the change with POST /api/admin/provisioning/jobs/{job}/return-payment, which returns the money and takes the change out of play.');

        return $exception->withContext(['transaction_id' => $transactionId, 'invoice_id' => $invoiceId])
            ->as('provisioning.refund_of_a_paid_change_in_play');
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
