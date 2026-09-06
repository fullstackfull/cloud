<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Enums;

/**
 * What the BMC says about one part, in the only three verdicts worth acting
 * on, plus the absence of a verdict.
 *
 * `Unknown` is kept apart from `Ok` on purpose. A controller that did not
 * report on a disk has not told us the disk is fine, and a fleet-wide health
 * report that counted silence as health would go green precisely when a BMC
 * stopped answering.
 */
enum ComponentHealth: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unknown = 'unknown';

    /**
     * Redfish states health as `Status.Health` with exactly these three
     * values; anything else — including the field being absent — is silence.
     */
    public static function fromRedfish(?string $health): self
    {
        return match ($health) {
            'OK' => self::Ok,
            'Warning' => self::Warning,
            'Critical' => self::Critical,
            default => self::Unknown,
        };
    }

    /**
     * Read an ipmitool sensor state: "ok", "ns" (no reading), "nc"
     * (non-critical), "cr"/"nr" (critical / non-recoverable).
     */
    public static function fromIpmiSensorState(string $state): self
    {
        return match (strtolower(trim($state))) {
            'ok' => self::Ok,
            'nc', 'warning' => self::Warning,
            'cr', 'nr', 'critical' => self::Critical,
            default => self::Unknown,
        };
    }

    /** Whether a person has to look at this. */
    public function needsAttention(): bool
    {
        return $this === self::Warning || $this === self::Critical;
    }

    /**
     * The worse of two verdicts, used to roll component readings up into one
     * machine-level answer.
     *
     * Unknown never improves a known verdict and never worsens one: a machine
     * with a critical disk and an unreported fan is critical, and a machine
     * that is entirely unreported stays unknown.
     */
    public function worseOf(self $other): self
    {
        $severity = [self::Ok->value => 1, self::Unknown->value => 2, self::Warning->value => 3, self::Critical->value => 4];

        return $severity[$other->value] > $severity[$this->value] ? $other : $this;
    }
}
