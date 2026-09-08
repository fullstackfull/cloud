<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Domain\Events;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;

/**
 * A subscription's status actually moved.
 *
 * One event rather than a family of SubscriptionSuspended / Reactivated /
 * Terminated classes, because the thing every listener needs is the pair: what
 * it was and what it now is. Enforcement differs by the edge, not the
 * destination — arriving at active from suspended means restoring a service
 * that was switched off, while arriving at active from past_due means nothing
 * needs doing to the machine at all — and a per-destination event throws away
 * the half of that which decides.
 *
 * Emitted only on a real change. TransitionSubscription converges when the
 * status already matches, and a redelivered webhook must not announce a
 * suspension that happened last week.
 */
final readonly class SubscriptionStatusChanged
{
    public function __construct(
        public string $subscriptionId,
        public string $customerId,
        public SubscriptionStatus $from,
        public SubscriptionStatus $to,
        public CarbonImmutable $changedAt,
    ) {}
}
