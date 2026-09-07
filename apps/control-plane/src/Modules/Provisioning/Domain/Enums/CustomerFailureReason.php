<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Enums;

/**
 * Why a piece of work did not succeed, in terms that are safe to publish.
 *
 * The alternative — showing the customer `last_error` — is what this enum
 * exists to prevent. That column holds whatever the provider said, and what a
 * provider says routinely names a hypervisor node, an internal address, a
 * cluster, or a request that carried a credential. The redactor strips the
 * credential; it cannot strip the topology, because the topology is not a
 * secret shape, it is just prose. So the raw message stays on the job for
 * administrators and support, and a customer is told the class of thing that
 * went wrong instead.
 *
 * The mapping is one-way and lossy on purpose. Four classes in, four words
 * out, and no free text anywhere: a customer-facing reason that could carry a
 * provider's sentence would eventually carry one.
 */
enum CustomerFailureReason: string
{
    /** Something was briefly unavailable. The platform will try again. */
    case TemporaryIssue = 'temporary_issue';

    /** There was no room for it where it was asked for. */
    case NoCapacity = 'no_capacity';

    /** The request itself was refused, and would be refused again. */
    case Rejected = 'rejected';

    /**
     * The platform stopped waiting before it heard back.
     *
     * Deliberately not called a failure. A timeout means the answer never
     * arrived, not that the work failed — the resource may well exist — and
     * telling a customer their server failed to build when it may be running
     * is the one message that guarantees the support ticket is wrong from the
     * first line.
     */
    case AwaitingConfirmation = 'awaiting_confirmation';

    public static function for(?FailureClass $failureClass): ?self
    {
        return match ($failureClass) {
            FailureClass::Transient => self::TemporaryIssue,
            FailureClass::Capacity => self::NoCapacity,
            FailureClass::Permanent => self::Rejected,
            FailureClass::Timeout => self::AwaitingConfirmation,
            null => null,
        };
    }
}
