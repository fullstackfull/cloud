<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Enums;

/**
 * What compensation did with the resources a failed job was holding.
 *
 * The distinction is recorded rather than inferred, because months later the
 * only way to explain why an address was still held is to be able to point at
 * the row that says a timeout put it there.
 */
enum CompensationAction: string
{
    /** Nothing was built, so the reservations went back into the pool. */
    case Released = 'released';

    /** Something may exist, so the reservations were held out of circulation. */
    case Quarantined = 'quarantined';

    /** There was nothing to compensate. */
    case NothingToDo = 'nothing_to_do';
}
