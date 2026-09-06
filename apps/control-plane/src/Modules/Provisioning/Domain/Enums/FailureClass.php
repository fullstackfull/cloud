<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Enums;

/**
 * Why a provisioning attempt failed, in the only terms the engine reasons in.
 *
 * The classification is not diagnostic decoration: it decides whether the
 * platform is allowed to try again, and whether the resources the attempt
 * reserved may be handed back. Those two decisions are the difference between
 * a self-healing platform and one that bills a customer for two servers.
 */
enum FailureClass: string
{
    /** The provider was momentarily unavailable. Nothing was built. */
    case Transient = 'transient';

    /** The request itself is wrong, and will be just as wrong next time. */
    case Permanent = 'permanent';

    /** The platform stopped waiting. What the provider did is unknown. */
    case Timeout = 'timeout';

    /** The provider had no room. Nothing was built, but retrying needs time. */
    case Capacity = 'capacity';

    /**
     * Whether the engine may retry this failure on its own.
     *
     * Timeout is excluded here in code and not only in configuration. A
     * timeout means the platform stopped waiting, not that the provider
     * stopped working: the resource may well exist. Retrying is how a customer
     * ends up with two servers, one of which the platform does not know about
     * and nobody is billed for. A config edit must not be able to turn that
     * back on, so the exclusion is enforced before config is consulted.
     */
    public function isAutomaticallyRetryable(): bool
    {
        if ($this === self::Timeout) {
            return false;
        }

        /** @var list<string> $retryable */
        $retryable = config('provisioning.retry.retryable_classes', ['transient', 'capacity']);

        return in_array($this->value, $retryable, true);
    }

    /**
     * Whether the resources this attempt reserved must be quarantined rather
     * than released.
     *
     * Releasing an address after a timeout hands it to the next customer while
     * a machine that may genuinely exist is still configured with it.
     */
    public function requiresQuarantine(): bool
    {
        return $this === self::Timeout;
    }

    /**
     * Whether a job that failed this way must be seen by a person.
     */
    public function requiresReview(): bool
    {
        return $this === self::Timeout;
    }
}
