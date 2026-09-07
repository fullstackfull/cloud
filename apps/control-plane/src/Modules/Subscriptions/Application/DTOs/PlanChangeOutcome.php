<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\DTOs;

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
        /** Null when nothing about the machine had to change. */
        public ?ProvisioningJob $resizeJob = null,
    ) {}

    /**
     * Whether the customer's server still has to change before this is over.
     */
    public function awaitsInfrastructure(): bool
    {
        return $this->resizeJob !== null;
    }
}
