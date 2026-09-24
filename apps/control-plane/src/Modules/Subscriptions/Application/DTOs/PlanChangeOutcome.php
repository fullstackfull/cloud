<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\DTOs;

use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * What a plan change actually did.
 *
 * Carries both halves separately because they finish at different times and a
 * customer needs to be told which is which: the money has moved, and the
 * machine is being resized. Collapsing them into "done" is how a dashboard
 * shows four vCPU on a machine running two.
 *
 * @immutable
 */
final readonly class PlanChangeOutcome
{
    public function __construct(
        public ProrationPlan $proration,
        public PlanChangeQuote $quote,
        /**
         * Null when nothing about the machine had to change, and also when
         * the change is an upgrade that has not been paid for yet: the resize
         * is queued by the settlement, not by the request.
         */
        public ?ProvisioningJob $resizeJob = null,
        /** The proration invoice, on an upgrade. Null when nothing is owed. */
        public ?Invoice $invoice = null,
    ) {}

    /**
     * Whether the customer owes money before any of this takes effect.
     */
    public function awaitsPayment(): bool
    {
        return $this->invoice !== null;
    }

    /**
     * Whether the customer's server still has to change before this is over.
     *
     * True for an unpaid upgrade as well as for a resize already queued.
     * Reading this off the job alone was briefly wrong in exactly the way this
     * DTO's own docblock warns about: the money moves, the machine does not,
     * and an upgrade awaiting payment would have reported itself finished
     * while the customer's server was still the old size.
     */
    public function awaitsInfrastructure(): bool
    {
        return $this->resizeJob !== null
            || ($this->invoice !== null && $this->quote->changesInfrastructure);
    }
}
