<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Enums;

use Lynomia\Modules\Dedicated\Domain\Contracts\DedicatedProvider;

/**
 * The three power operations a customer may ask for on their own machine.
 *
 * Three, and no more. The out-of-band controller can do more than this —
 * {@see DedicatedProvider} exposes
 * a hard power cut as well as a polite one — and the difference between the
 * two is a customer's unflushed writes. So the customer surface names the
 * polite one `off` and does not publish the other at all: pulling the cord on
 * a physical host is an operator's decision, taken after somebody has looked
 * at why the machine will not stop.
 *
 * There is deliberately no `force` boolean anywhere near this. A flag that
 * turns a graceful request into a destructive one is a flag some client
 * library defaults, some convenience wrapper sets and some retry loop resends;
 * a verb has to be asked for by name.
 *
 * `cycle` is the escape hatch for a machine that has stopped listening, and it
 * is the reason `off` can afford to be graceful: a host that ignores ACPI is
 * still recoverable through the reset line without the API ever offering
 * "cut the power and leave it off".
 */
enum DedicatedPowerAction: string
{
    /** Bring the chassis up. */
    case On = 'on';

    /**
     * Ask the operating system to shut down, over ACPI.
     *
     * Requires a host that is listening. It does not escalate to a hard power
     * cut on its own, and nothing in this module escalates for it: "the
     * machine did not answer" and "the machine should lose power" are not the
     * same judgement, and only one of them loses data.
     */
    case Off = 'off';

    /**
     * Make the machine boot, whatever it is doing now.
     *
     * Not "off then on": the platform never holds a customer's machine down
     * between two operations, because a worker that dies in the gap leaves the
     * server switched off with nobody coming back for it.
     */
    case Cycle = 'cycle';

    /**
     * Whether this action asks the guest operating system rather than the
     * hardware.
     *
     * The one place the distinction is read is the retry policy: a host that
     * ignored one ACPI request will ignore the next, so a graceful request is
     * never repeated on the customer's behalf.
     */
    public function isGraceful(): bool
    {
        return $this === self::Off;
    }
}
