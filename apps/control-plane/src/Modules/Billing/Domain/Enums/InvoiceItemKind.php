<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Enums;

/**
 * What a single invoice line is charging for.
 *
 * The vocabulary matches the order and subscription side of the platform so a
 * line can be traced from the order that created it to the invoice that billed
 * it without a translation table in between.
 */
enum InvoiceItemKind: string
{
    case Plan = 'plan';
    case Addon = 'addon';
    case Usage = 'usage';
    case Proration = 'proration';
    case Credit = 'credit';
    case Setup = 'setup';
}
