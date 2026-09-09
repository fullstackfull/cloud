<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Enums;

/**
 * How much of a machine an operator has agreed we may touch.
 *
 * This is the question that comes before every other one. The per-operation
 * flags in the Ansible inventory — allow_reimage, pxe_allow_serve_dhcp,
 * proxmox_cluster_join_enabled — answer "is this specific destructive thing
 * scheduled for today". This answers "may we connect to the machine and write
 * to it at all", and it is deliberately the same four-way vocabulary the
 * infrastructure tree uses, because two names for one idea is how a
 * classification gets skipped.
 *
 * The default is DoNotTouch and nothing may quietly raise it. An unused-looking
 * disk is not evidence that a disk is spare, and a quiet server is not evidence
 * that a server is free.
 */
enum SafetyClass: string
{
    /** Do not connect to it for any write. The default, and the answer for any machine whose owner has not spoken. */
    case DoNotTouch = 'do_not_touch';

    /** Read facts, change nothing. Where every machine starts once somebody claims it. */
    case DiscoveryOnly = 'discovery_only';

    /** Change configuration. Never partitions, RAID, firmware or boot order. */
    case ConfigurationAllowed = 'configuration_allowed';

    /** May be wiped and reinstalled — and only then, and only with the machine's own allow_reimage. */
    case ReimageAllowed = 'reimage_allowed';

    /**
     * Does this class permit the given action?
     *
     * Reimage deliberately answers false here even for ReimageAllowed: the
     * class is necessary and not sufficient, and the second half of the
     * decision belongs to the individual machine.
     */
    public function permits(InfrastructureAction $action): bool
    {
        return match ($this) {
            self::DoNotTouch => false,
            self::DiscoveryOnly => $action === InfrastructureAction::Read,
            self::ConfigurationAllowed => in_array($action, [InfrastructureAction::Read, InfrastructureAction::Configure], strict: true),
            self::ReimageAllowed => true,
        };
    }

    /**
     * The classes an operator may move to from here, in one step.
     *
     * Onboarding walks up this ladder one rung at a time on purpose. A machine
     * that arrives DoNotTouch and is immediately ReimageAllowed has skipped the
     * read-only look that would have told somebody what is on its disks.
     */
    public function mayBecome(self $next): bool
    {
        if ($this === $next) {
            return false;
        }

        // Lowering is always allowed: deciding to touch a machine less is never
        // the dangerous direction.
        if ($next->rank() < $this->rank()) {
            return true;
        }

        return $next->rank() === $this->rank() + 1;
    }

    /** Whether reaching this class is one of the strongest acts an operator can perform. */
    public function isDestructive(): bool
    {
        return $this === self::ReimageAllowed;
    }

    private function rank(): int
    {
        return match ($this) {
            self::DoNotTouch => 0,
            self::DiscoveryOnly => 1,
            self::ConfigurationAllowed => 2,
            self::ReimageAllowed => 3,
        };
    }
}
