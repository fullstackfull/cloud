<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Enums;

use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;

/**
 * Whether a provider can actually do one specific thing.
 *
 * Unknown is the default and is not a failure — it means nobody has asked. The
 * platform has spent three phases removing capabilities that existed in code
 * and could not complete in the product, so a capability the codebase declares
 * and the provider has never confirmed stays Unknown until a connection test
 * says otherwise.
 */
enum CapabilityState: string
{
    case Supported = 'supported';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';
    case BlockedLicence = 'blocked_licence';
    case BlockedCredentials = 'blocked_credentials';
    case BlockedConfiguration = 'blocked_configuration';
    case BlockedNetwork = 'blocked_network';

    /** May the platform rely on this capability right now? */
    public function usable(): bool
    {
        return $this === self::Supported;
    }

    public function blocker(): ?BlockerReason
    {
        return match ($this) {
            self::Supported, self::Unsupported, self::Unknown => null,
            self::BlockedLicence => BlockerReason::Licence,
            self::BlockedCredentials => BlockerReason::Credentials,
            self::BlockedConfiguration => BlockerReason::Configuration,
            self::BlockedNetwork => BlockerReason::Network,
        };
    }
}
