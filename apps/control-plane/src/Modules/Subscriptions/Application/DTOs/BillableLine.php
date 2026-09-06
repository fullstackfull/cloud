<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\DTOs;

use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * One line a subscription wants billed, paired with what kind of line it is.
 *
 * The subscription module produces these and stops. It never prices them and
 * never issues an invoice: tax resolution, discount allocation and invoice
 * numbering all belong to billing, and a subscription that reached into that
 * would end up with a second, subtly different, copy of the pricing rules.
 *
 * @immutable
 */
final readonly class BillableLine
{
    public function __construct(
        public InvoiceItemKind $kind,
        public PricingLine $line,
    ) {}

    public function amount(): Money
    {
        return $this->line->gross();
    }
}
