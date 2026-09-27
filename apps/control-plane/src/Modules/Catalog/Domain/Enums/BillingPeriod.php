<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Enums;

use Carbon\CarbonImmutable;
use DateTimeInterface;

enum BillingPeriod: string
{
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    /**
     * Advances a period start to the corresponding period end.
     *
     * Calendar-aware for the monthly and longer periods: a subscription that
     * starts on 31 January must renew on 28 February, not overflow into March.
     * Carbon's addMonthsNoOverflow is what enforces that.
     */
    public function advance(DateTimeInterface $from): CarbonImmutable
    {
        $start = CarbonImmutable::instance($from);

        return match ($this) {
            self::Hourly => $start->addHour(),
            self::Daily => $start->addDay(),
            self::Monthly => $start->addMonthNoOverflow(),
            self::Quarterly => $start->addMonthsNoOverflow(3),
            self::Yearly => $start->addYearNoOverflow(),
        };
    }

    /**
     * Steps a period start back by one period: no later than the start of
     * the period before it.
     *
     * Not an exact inverse of advance(): a month that advance() clamped (31
     * January to 28 February) steps back to the 28th, three days before the
     * start it came from. What reads it wants a bound that is never later
     * than the previous period's start, which that is.
     */
    public function retreat(DateTimeInterface $from): CarbonImmutable
    {
        $start = CarbonImmutable::instance($from);

        return match ($this) {
            self::Hourly => $start->subHour(),
            self::Daily => $start->subDay(),
            self::Monthly => $start->subMonthNoOverflow(),
            self::Quarterly => $start->subMonthsNoOverflow(3),
            self::Yearly => $start->subYearNoOverflow(),
        };
    }

    /**
     * Whether a period is short enough that usage-style metering, rather than
     * a fixed recurring invoice, is the sensible billing model.
     */
    public function isMetered(): bool
    {
        return $this === self::Hourly;
    }

    /**
     * Approximate months in the period, used only for comparing plans in the
     * UI. Never used for money: proration works from actual dates.
     */
    public function approximateMonths(): float
    {
        return match ($this) {
            self::Hourly => 1 / 730,
            self::Daily => 1 / 30,
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
        };
    }
}
