<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Enums;

/**
 * What a registrar said when asked whether a name can be bought.
 *
 * ---------------------------------------------------------------------------
 * Why this is not a boolean
 * ---------------------------------------------------------------------------
 *
 * Because two of the five answers are not "yes" or "no", and both of them
 * cost somebody money when they are flattened into one.
 *
 * `Unknown` is the important one. A registrar that times out has told the
 * platform nothing. Rendering that as available invites a customer to buy a
 * name that is taken and get a refusal at checkout; rendering it as
 * unavailable tells a customer a name they could have had is gone. Both are
 * worse than a screen that says the search did not answer and offers to try
 * again — which is the only honest thing to draw.
 *
 * `Premium` is the second. The name is available and its price is not the
 * TLD's price; a search that reported it as plainly available would show the
 * ordinary price beside it, which is the exact substitution the quote table
 * exists to prevent.
 */
enum DomainAvailability: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';

    /** Available, at a price the registry sets for this specific name. */
    case Premium = 'premium';

    /** The registrar did not answer. Not a yes and not a no. */
    case Unknown = 'unknown';

    /** This platform does not sell this namespace. */
    case Unsupported = 'unsupported';

    /**
     * Whether a customer may be offered a purchase from this answer.
     *
     * Unknown is excluded, and that is the whole value of the enum: a
     * checkout built from an unanswered search is a checkout that fails at the
     * registrar with the customer's money already moving.
     */
    public function isPurchasable(): bool
    {
        return $this === self::Available || $this === self::Premium;
    }

    /**
     * Whether the price for this answer must come from a per-name quote rather
     * than from the TLD's price list.
     */
    public function needsItsOwnPrice(): bool
    {
        return $this === self::Premium;
    }
}
