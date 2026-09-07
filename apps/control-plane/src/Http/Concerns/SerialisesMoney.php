<?php

declare(strict_types=1);

namespace Lynomia\Http\Concerns;

use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * The one shape money takes on the wire.
 *
 *     {"minor_units": 9000, "currency": "KWD", "amount": "9.000"}
 *
 * All three, always. `minor_units` is what arithmetic uses, `currency` is what
 * makes the number mean anything, and `amount` is the exact decimal for display
 * so a client never has to know that KWD has three of them and USD two. A
 * response that carried only the decimal would invite a client to parse it into
 * a float and add it to something.
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

    /**
     * For the many rows that store an amount and its currency as two columns.
     *
     * @return array{minor_units: int, currency: string, amount: string}
     */
    protected function moneyOfMinor(int $minorUnits, string $currency): array
    {
        return Money::ofMinor($minorUnits, $currency)->jsonSerialize();
    }
}
