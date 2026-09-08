<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Enums;

use InvalidArgumentException;

/**
 * What suspending a customer's machine actually does to it at the hypervisor.
 *
 * ---------------------------------------------------------------------------
 * Why this is a policy and not a boolean
 * ---------------------------------------------------------------------------
 *
 * Phase 29 deliberately refused to implement suspension as "stop the VM", and
 * the reasoning still holds: at the hypervisor a stop is indistinguishable
 * from the customer stopping their own machine, and nothing about a stopped VM
 * prevents it being started again. The platform's own guard refuses the
 * customer's start request — but a guard in one application is not
 * enforcement, it is one bug or one forgotten check away from nothing.
 *
 * Suspension is a service policy state. The provider integration has to make
 * that state true at the provider, using semantics the provider actually has.
 * These are Proxmox's, and nothing here invents any:
 *
 *  - **`lock`** — Proxmox's own config lock. A locked VM refuses start, stop,
 *    resize and config changes from any caller, including somebody typing `qm
 *    start` on the node. This is the part a customer cannot undo.
 *  - **`onboot=0`** — so a node reboot does not resurrect a suspended machine.
 *    Without it, suspension survives exactly until the next maintenance
 *    window, and the customer's server quietly comes back unpaid.
 *  - **shutdown, then stop** — the guest is asked first. A customer who is
 *    late paying has not consented to losing unflushed writes, and the
 *    difference between asking and pulling the plug is their database.
 *
 * ---------------------------------------------------------------------------
 * Why more than one value
 * ---------------------------------------------------------------------------
 *
 * A platform may reasonably want the machine left running while it is
 * network-isolated, or may be operating somewhere that suspension means
 * something else legally. The values are a closed set rather than a set of
 * booleans so that "what does suspension do here" has one answer that can be
 * read in configuration, and so an unsupported combination cannot be spelled.
 */
enum SuspensionPolicy: string
{
    /**
     * The name the platform writes into the provider's config lock.
     *
     * A distinct value, not the provider's own vocabulary: reconciliation has
     * to be able to tell a lock Lynomia placed from one a backup or a
     * migration placed, and clearing somebody else's would have the platform
     * interfering with an operation it did not start.
     */
    public const string LOCK_NAME = 'lynomia-suspended';

    /**
     * Ask the guest to shut down, stop it if it will not, clear onboot, and
     * lock it. The default, and the only value that is enforcement rather
     * than bookkeeping.
     */
    case PowerOffAndLock = 'power_off_and_lock';

    /**
     * Power off and clear onboot, without the lock.
     *
     * For a platform whose operators need to be able to start a suspended
     * machine without going through Lynomia — a diagnostic estate, say. The
     * customer still cannot start it, because the API refuses; but nothing at
     * the hypervisor stops anybody who can reach it.
     */
    case PowerOff = 'power_off';

    /**
     * Record the suspension and change nothing at the provider.
     *
     * Honest rather than useful: it is what the platform did before this
     * phase, and naming it means a deployment that wants that behaviour has
     * to choose it rather than get it by an omission nobody noticed.
     */
    case RecordOnly = 'record_only';

    /**
     * Whether this policy expects the machine to be powered off.
     */
    public function powersOff(): bool
    {
        return $this !== self::RecordOnly;
    }

    /**
     * Whether this policy expects the provider to refuse the customer's own
     * start — the difference between a policy and a note.
     */
    public function locksAtProvider(): bool
    {
        return $this === self::PowerOffAndLock;
    }

    /**
     * Whether the platform must confirm the provider before calling a service
     * active again.
     *
     * With RecordOnly there is nothing at the provider to confirm, so
     * requiring verification would leave every reactivation stuck waiting for
     * a fact that does not exist.
     */
    public function requiresProviderVerification(): bool
    {
        return $this !== self::RecordOnly;
    }

    public static function configured(): self
    {
        $value = config('compute.suspension_policy');

        if ($value === null || $value === '') {
            // Unset is not misconfigured: the strictest policy is the default
            // precisely so that a deployment which says nothing gets real
            // enforcement rather than bookkeeping.
            return self::PowerOffAndLock;
        }

        $policy = is_string($value) ? self::tryFrom($value) : null;

        if ($policy === null) {
            /*
             * Refused rather than defaulted. A typo silently resolving to a
             * policy is how an operator ends up believing they configured
             * `record_only` for a migration window while the platform locks
             * machines — or, worse, believing they configured enforcement
             * while it does nothing. Both are discovered in an incident.
             */
            throw new InvalidArgumentException(sprintf(
                'compute.suspension_policy is set to "%s", which is not a suspension policy. '
                .'Valid values are: %s.',
                is_scalar($value) ? (string) $value : get_debug_type($value),
                implode(', ', array_column(self::cases(), 'value')),
            ));
        }

        return $policy;
    }
}
