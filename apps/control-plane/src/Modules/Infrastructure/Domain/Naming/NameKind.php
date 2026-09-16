<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Naming;

/**
 * What kind of name a field holds — which decides what may change it.
 *
 * ===========================================================================
 * FOUR KINDS, NOT ONE "NAME"
 * ===========================================================================
 *
 * This platform stores four different things that all look like a name, and
 * treating them as one is how a rename breaks an order, or a DNS change
 * orphans a monitoring series, or a provider request goes out with the wrong
 * spelling of a node.
 *
 *  - A LOGICAL KEY is ours and it is durable. Orders, audit entries, desired
 *    state, deployment plans and metric labels reference it. It survives the
 *    hostname changing, the provider changing, and somebody deciding the rack
 *    should be called something friendlier.
 *  - An OPERATOR CODE is also ours and also unique, but it is a label people
 *    chose and may re-choose — the code stencilled on a rack, the name an
 *    operator gave a provider instance. Same syntax as a logical key, weaker
 *    promise.
 *  - A NETWORK NAME is a hostname. It changes when DNS or the network changes,
 *    which is somebody else's schedule, and it must never be the thing a
 *    durable reference points at.
 *  - A PROVIDER-NATIVE ID belongs to another system's naming scheme. Proxmox
 *    decides what its nodes and storages are called; a registrar decides what
 *    an account id looks like. The platform stores it exactly as that system
 *    spells it and never normalises it, because a normalised copy of somebody
 *    else's identifier is a value their API does not recognise.
 *  - A DISPLAY NAME is for people. Any script, any wording, renameable at will,
 *    and load-bearing for nothing.
 */
enum NameKind: string
{
    case LogicalKey = 'logical_key';
    case OperatorCode = 'operator_code';
    case NetworkName = 'network_name';
    case ProviderNative = 'provider_native';
    case DisplayName = 'display_name';

    /**
     * Is a value of this kind referenced durably enough that changing it is a
     * migration rather than an edit?
     */
    public function isStableIdentity(): bool
    {
        return $this === self::LogicalKey;
    }

    /**
     * Is this a value the platform may canonicalise on creation?
     *
     * Never for a provider-native id: their scheme, their case rules. Never for
     * a display name: that would rewrite somebody's wording.
     */
    public function isCanonicalisable(): bool
    {
        return match ($this) {
            self::LogicalKey, self::OperatorCode, self::NetworkName => true,
            self::ProviderNative, self::DisplayName => false,
        };
    }

    /** What to call this kind of name in a sentence. */
    public function label(): string
    {
        return match ($this) {
            self::LogicalKey => 'logical identifier',
            self::OperatorCode => 'operator code',
            self::NetworkName => 'hostname',
            self::ProviderNative => 'provider-native identifier',
            self::DisplayName => 'display name',
        };
    }

    /** One sentence an operator can read on a form beside the field. */
    public function explanation(): string
    {
        return match ($this) {
            self::LogicalKey => 'Stable identifier. Orders, audit history and monitoring reference it; changing the display name does not change this value.',
            self::OperatorCode => 'Operator code. Unique, and yours to choose; it identifies this thing on screens and in the configuration.',
            self::NetworkName => 'Network identity. It must resolve in the configured environment, and it may change when DNS or the network does.',
            self::ProviderNative => 'The provider\'s own identifier. Stored exactly as the provider spells it, because it is sent back to the provider.',
            self::DisplayName => 'Human label. Rename it freely: nothing references it.',
        };
    }
}
