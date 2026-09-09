<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Enums;

/**
 * Where a fact about a machine came from.
 *
 * The rule this exists to enforce: a number somebody typed does not silently
 * overwrite a number the hardware reported, and neither silently overwrites the
 * other. When they disagree, that disagreement is the interesting thing — it
 * usually means somebody pulled a DIMM.
 */
enum FactSource: string
{
    /** A person wrote it down. Asset tags, rack position, the reason we bought it. */
    case Declared = 'declared';

    /** The machine or its BMC said so. */
    case Discovered = 'discovered';

    /** The platform worked it out from other facts. */
    case Derived = 'derived';

    /**
     * May a fact from this source replace one from that source?
     *
     * Discovery wins over a person's note about the same field, because the
     * hardware is the authority on its own RAM. A person's note is never
     * *lost* — it is kept alongside, and the difference is shown.
     */
    public function mayReplace(self $existing): bool
    {
        return match ([$this, $existing]) {
            [self::Discovered, self::Declared] => true,
            [self::Discovered, self::Derived] => true,
            [self::Declared, self::Discovered] => false,
            default => $this === $existing,
        };
    }
}
