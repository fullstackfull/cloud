<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Enums;

/**
 * Whether a name in redemption can be recovered through this platform, and
 * if not, why not — in the words the readiness matrix uses.
 *
 *   supported              the registrar can redeem and the platform knows the
 *                          registry's penalty for this namespace
 *   unsupported            the registrar has said it cannot
 *   unknown                the registrar has not said either way — the `.sy`
 *                          case, where no published policy exists and the
 *                          platform refuses to invent one
 *   blocked_configuration  the registrar could, and this platform has not been
 *                          told the penalty or the windows for the namespace,
 *                          so it will not quote a guess
 *
 * The customer sees each of these as its own sentence. What they never see is
 * a price that was made up, or a button that leads to a refusal.
 */
enum RedemptionSupport: string
{
    case Supported = 'supported';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';
    case BlockedConfiguration = 'blocked_configuration';

    public function isAvailable(): bool
    {
        return $this === self::Supported;
    }
}
