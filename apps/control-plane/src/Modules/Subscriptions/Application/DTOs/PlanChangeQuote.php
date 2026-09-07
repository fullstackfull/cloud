<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\DTOs;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Domain\Enums\PlanChangeRefusal;
use Lynomia\Modules\Subscriptions\Domain\ValueObjects\PlanResources;

/**
 * What moving to one particular plan would cost and change.
 *
 * Every number here is computed by the same PricingEngine call the apply path
 * makes, against the same period and the same instant. That is the point of
 * the object: the quote a customer confirms and the invoice they are charged
 * are not two calculations that ought to agree, they are one calculation
 * performed twice — and nothing in the portal is allowed to do arithmetic on
 * these values beyond rendering them.
 *
 * A quote that cannot be taken carries its reason instead of its numbers. That
 * is deliberate: showing a price for a plan the platform would refuse is how a
 * customer decides to buy something and then gets an error.
 *
 * @immutable
 */
final readonly class PlanChangeQuote
{
    /**
     * @param  list<PlanChangeRefusal>  $refusals  Empty when the change may be made.
     * @param  list<string>  $warnings  True and worth saying, but not disqualifying.
     */
    public function __construct(
        public string $planId,
        public string $priceId,
        public string $planName,
        public Money $currentRecurring,
        public Money $newRecurring,
        public Money $credit,
        public Money $charge,
        public Money $amountDueNow,
        public CarbonImmutable $effectiveAt,
        public CarbonImmutable $periodEnd,
        public PlanResources $currentResources,
        public PlanResources $newResources,
        public bool $changesInfrastructure,
        public array $refusals = [],
        public array $warnings = [],
    ) {}

    public function isAvailable(): bool
    {
        return $this->refusals === [];
    }
}
