<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Preflight;

/**
 * Which world a preflight is asking about.
 *
 * ===========================================================================
 * TWO, AND THERE IS NO THIRD
 * ===========================================================================
 *
 * There is no AUTO, no FULL and no WRITE, and the absence is the design. An
 * AUTO mode decides for the operator which world they are looking at, and the
 * one thing a preflight must never be vague about is whether the thing it just
 * proved was real. A WRITE mode would make "preflight" a word that sometimes
 * provisions, and the next person to add a check would have no way to know
 * which kind they were writing.
 *
 * The mode is a required argument at every call site. Nothing defaults.
 */
enum PreflightMode: string
{
    /**
     * Controlled providers, reference configuration, stateful fakes.
     *
     * Proves the software orchestration works. It can earn CODE_COMPLETE,
     * TESTED and RUNTIME_VERIFIED, and it can never earn any REAL_ claim — a
     * fake answering correctly is evidence about this codebase and no evidence
     * at all about an estate.
     */
    case Simulation = 'simulation';

    /**
     * Real endpoints, real credentials, reads only.
     *
     * May validate configuration, resolve credential presence, apply the
     * endpoint policy, run a provider identity test, read capabilities,
     * perform discovery whose contract is guaranteed read-only, and read
     * licence, monitoring and backup state. It may not create, delete, start,
     * stop, reboot, resize, reinstall, restore, back up, publish DNS, register
     * or renew a domain, charge or refund, create a hosting account, touch a
     * BMC, or apply infrastructure.
     */
    case ReadOnlyReal = 'read_only_real';

    /**
     * May a result in this mode support a REAL_ verification claim at all?
     *
     * False for simulation, always, and this is the method the rest of the
     * platform asks rather than comparing the enum itself — so that a future
     * mode cannot be added without somebody answering this question for it.
     */
    public function mayEvidenceReality(): bool
    {
        return $this === self::ReadOnlyReal;
    }

    /**
     * The word that goes at the top of every report and every screen.
     *
     * A simulation result that does not say SIMULATION is a simulation result
     * somebody will quote as proof.
     */
    public function label(): string
    {
        return match ($this) {
            self::Simulation => 'SIMULATION',
            self::ReadOnlyReal => 'READ_ONLY_REAL',
        };
    }
}
