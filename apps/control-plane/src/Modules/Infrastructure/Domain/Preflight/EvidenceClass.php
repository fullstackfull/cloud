<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Preflight;

/**
 * Where a check's answer came from, which decides what it may be used to
 * claim.
 *
 * ===========================================================================
 * WHY THIS IS ON EVERY CHECK
 * ===========================================================================
 *
 * Because a preflight report mixes three completely different kinds of
 * knowledge in one list, and printed side by side they look identical:
 *
 *   "storage mapping present"      — read from our own database
 *   "provider identity: Proxmox"   — a fake said so
 *   "provider identity: Proxmox"   — a real cluster said so
 *
 * The first says nothing about any machine anywhere. The second says the
 * software works. Only the third is evidence about an estate, and only for
 * the exact read that produced it. Without this field on each check, the
 * report's own summary cannot tell them apart, and the summary is the thing
 * people quote.
 */
enum EvidenceClass: string
{
    /**
     * Read from this platform's own records.
     *
     * Entirely trustworthy about what an operator has configured, and no
     * evidence whatsoever that the configured thing exists.
     */
    case Configuration = 'configuration';

    /**
     * A controlled provider answered.
     *
     * Evidence that the orchestration around it works. Never evidence about
     * real infrastructure, and {@see PreflightMode::mayEvidenceReality()} is
     * the gate that enforces it.
     */
    case Simulation = 'simulation';

    /**
     * A real endpoint answered a read, over a connection whose identity was
     * proven by the provider's own evidence.
     *
     * The only class that can support a REAL_ claim, and only for the capability
     * the read actually exercised. A real cluster answering `/version` is
     * evidence that this platform can authenticate to and read from that
     * cluster. It is not evidence that a virtual machine will build on it.
     */
    case RealRead = 'real_read';

    /** Nothing was established, so there is nothing to classify. */
    case None = 'none';

    public function mayClaimReality(): bool
    {
        return $this === self::RealRead;
    }
}
