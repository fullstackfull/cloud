<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Enums;

/**
 * Which call left a record `indeterminate`.
 *
 * The state says nobody knows whether the provider did what it was asked;
 * this says what it was asked. Reconciliation cannot settle the row without
 * it, because the same observation means opposite things:
 *
 *  - the value is **not** in the zone: after a delete, that is the answer the
 *    platform was waiting for (`deleted`); after a publish, the record never
 *    arrived, and calling it deleted would take it out of the customer's
 *    listing while they still believe it is theirs;
 *  - the value **is** in the zone: after a publish, it landed (`active`);
 *    after a delete, the delete did not land, and calling the row `active`
 *    would stamp a record the customer asked to remove as live and clear the
 *    evidence that anything went wrong.
 *
 * Null on a row means the platform does not know — every row written before
 * the column existed, and every row not indeterminate. A sweep settles
 * neither arm for such a row: settling a row it cannot read is wrong whichever
 * way it guesses.
 */
enum IndeterminateAfter: string
{
    case Publish = 'publish';
    case Delete = 'delete';
}
