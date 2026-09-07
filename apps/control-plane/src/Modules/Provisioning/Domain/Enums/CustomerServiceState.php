<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Enums;

/**
 * The whole vocabulary a customer is given for where their service stands.
 *
 * Seven words, and deliberately no more. Everything the platform knows about a
 * service is richer than this — which provider is fulfilling it, which node,
 * which attempt failed and what the provider said while failing — and none of
 * that is the customer's to read. A customer-facing state is not a projection
 * of the operational one with the sensitive fields removed; it is a separate,
 * small vocabulary that the operational states are mapped onto, so that adding
 * an internal state later cannot widen this list by accident.
 *
 * `under_review` is the case that earns the enum. A job that timed out or
 * exhausted its attempts settles in `needs_review`: the platform stopped
 * waiting, what the provider did is unknown, and nothing will be retried until
 * a person has looked. The service row itself may still say `provisioning` —
 * the stale-job sweeper deliberately does not touch it — and reporting
 * "provisioning" for a build that nobody is working on any more is the lie
 * this state exists to stop telling. It says, in the only terms a customer
 * needs: this is not moving on its own, and somebody knows.
 */
enum CustomerServiceState: string
{
    /** Bought, and not started yet. */
    case Pending = 'pending';

    /** Being built right now. */
    case Provisioning = 'provisioning';

    /** Usable. */
    case Active = 'active';

    /** Stopped, and restorable. */
    case Suspended = 'suspended';

    /**
     * Paid for, coming back, not yet usable.
     *
     * Its own word rather than folding into `provisioning` or `active`. The
     * customer has just paid and is watching: telling them the service is
     * active when the hypervisor is still refusing them would be the one
     * message they immediately disprove, and calling it `provisioning` reads
     * as though the machine is being rebuilt.
     */
    case Reactivating = 'reactivating';

    /** Gone, and not coming back. */
    case Terminated = 'terminated';

    /** The build did not succeed and will not be retried on its own. */
    case Failed = 'failed';

    /** Stopped mid-flight and waiting on a person, not on a machine. */
    case UnderReview = 'under_review';

    /**
     * What a customer is told, given where the service stands and whether any
     * of its provisioning work is still waiting on a person.
     *
     * The second argument is deliberately a fact about the whole service and
     * not about its most recent job. A service accumulates jobs, so "the last
     * one" stops being the stuck one the moment anything else happens to it,
     * and a build nobody is working on would quietly go back to reading
     * `provisioning`.
     *
     * Review only ever overrides a service that is not yet delivered. A failed
     * reboot of a running server is not a failed server: `Active`, `Suspended`
     * and `Terminated` are facts about what the customer owns, and no job
     * outcome is allowed to talk over them.
     */
    public static function for(ServiceStatus $status, bool $isAwaitingReview = false): self
    {
        $state = match ($status) {
            ServiceStatus::Pending => self::Pending,
            ServiceStatus::Provisioning => self::Provisioning,
            ServiceStatus::Active => self::Active,
            ServiceStatus::Suspended => self::Suspended,
            ServiceStatus::Reactivating => self::Reactivating,
            ServiceStatus::Terminated => self::Terminated,
            ServiceStatus::Failed => self::Failed,
        };

        if ($isAwaitingReview && $state->isEclipsedByReview()) {
            return self::UnderReview;
        }

        return $state;
    }

    /**
     * Whether a job waiting on a person speaks louder than this state.
     *
     * True exactly for the states that describe a service the platform is
     * still trying to deliver.
     */
    public function isEclipsedByReview(): bool
    {
        return match ($this) {
            self::Pending, self::Provisioning, self::Failed => true,
            /*
             * Reactivating is a fact about what the customer owns and owes,
             * not about a build in progress, so a stuck job does not talk over
             * it — same reasoning as Active and Suspended.
             */
            self::Active, self::Suspended, self::Reactivating,
            self::Terminated, self::UnderReview => false,
        };
    }

    /**
     * The service statuses a row in this customer-facing state can be sitting
     * in, so that filtering a list by one of these words asks the database the
     * same question the serialiser answers.
     *
     * Kept here rather than in the query that uses it: two implementations of
     * this mapping is how `?state=active` comes to mean something other than
     * the `active` the response prints.
     *
     * @return list<ServiceStatus>
     */
    public function underlyingStatuses(): array
    {
        return match ($this) {
            self::Pending => [ServiceStatus::Pending],
            self::Provisioning => [ServiceStatus::Provisioning],
            self::Active => [ServiceStatus::Active],
            self::Suspended => [ServiceStatus::Suspended],
            self::Reactivating => [ServiceStatus::Reactivating],
            self::Terminated => [ServiceStatus::Terminated],
            self::Failed => [ServiceStatus::Failed],
            // A service waiting on a person can be sitting in any of the three
            // undelivered statuses, depending on whether the sweeper or the
            // engine settled its job.
            self::UnderReview => [ServiceStatus::Pending, ServiceStatus::Provisioning, ServiceStatus::Failed],
        };
    }
}
