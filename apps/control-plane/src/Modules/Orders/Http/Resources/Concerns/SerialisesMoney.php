<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Http\Resources\Concerns;

use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * One money shape for everything this module serialises.
 *
 * A bare number would force every client to know how many minor digits the
 * currency has — three for KWD, two for USD, zero for JPY — and the first one
 * that guesses two prints 90.00 KWD for a 9.000 KWD order. So the currency
 * travels with the amount, the integer minor units are the authoritative field,
 * and the decimal string is rendered by Money rather than by division.
 */
trait SerialisesMoney
{
    /**
     * @return array{minor_units: int, currency: string, amount: string}
     */
    protected function money(int $minorUnits, string $currency): array
    {
        return Money::ofMinor($minorUnits, $currency)->jsonSerialize();
    }
}
