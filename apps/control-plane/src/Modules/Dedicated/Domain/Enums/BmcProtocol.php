<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Enums;

/**
 * How the platform talks to one out-of-band controller.
 *
 * The order of preference is a security and correctness judgement, not a
 * stylistic one, and {@see self::preferenceRank()} encodes it:
 *
 *  - Redfish is a specified HTTP API with real error semantics, so a refusal
 *    can be told apart from a timeout — which is the distinction the whole
 *    provisioning engine turns on;
 *  - iLO is vendor-specific and used only where HPE's Redfish implementation
 *    is incomplete;
 *  - IPMI is last, always. It takes its password on the command line, has no
 *    transport security worth the name, and reports failure as unparsed
 *    English on stderr.
 */
enum BmcProtocol: string
{
    case Redfish = 'redfish';
    case Ilo = 'ilo';
    case Ipmi = 'ipmi';

    /**
     * Lower is better. A server with several endpoints is reached through the
     * best one it has, which is how a fleet migrates off IPMI a machine at a
     * time without a single caller learning what protocol it is speaking.
     */
    public function preferenceRank(): int
    {
        return match ($this) {
            self::Redfish => 0,
            self::Ilo => 1,
            self::Ipmi => 2,
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::Redfish, self::Ilo => 443,
            self::Ipmi => 623,
        };
    }

    /** Whether this protocol is HTTP, and therefore has status codes to reason about. */
    public function isHttp(): bool
    {
        return $this !== self::Ipmi;
    }

    /**
     * Whether using this protocol is an admission that nothing better exists.
     *
     * Read by inventory reporting so an operator can see how much of the fleet
     * is still reachable only by the dangerous route.
     */
    public function isLastResort(): bool
    {
        return $this === self::Ipmi;
    }
}
