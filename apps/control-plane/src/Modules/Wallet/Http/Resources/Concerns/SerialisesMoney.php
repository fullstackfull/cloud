<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Http\Resources\Concerns;

use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * One money shape for everything this module serialises.
 *
 * A bare number would force every client to know how many minor digits the
 * currency has — three for KWD, two for USD, zero for JPY — and the first one
 * that guesses two shows a 25.000 KWD balance as 250.00. So the currency
 * travels with the amount, the integer minor units are the authoritative
 * field, and the decimal string is rendered by Money rather than by division.
 *
 * Near-identical traits exist in Billing, Payments and Orders. Duplicated for
 * the reason stated there: a resource trait is part of a module's HTTP
 * surface, and eleven lines of formatting are not worth coupling two surfaces.
 */
trait SerialisesMoney
{
    /**
     * @return array{minor_units: int, currency: string, amount: string}
     */
    protected function money(Money $amount): array
    {
        return $amount->jsonSerialize();
    }
}
