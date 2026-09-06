<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Enums;

/**
 * What the chassis is doing, as last observed through the BMC.
 *
 * `Unknown` is a real state and not a null stand-in: a BMC that did not answer
 * tells the platform nothing about the host, and that is different from
 * knowing the host is off. Provisioning branches on the difference — power
 * cycling a machine believed to be off, when it is in fact mid-install, is a
 * corrupted filesystem — so the two are never collapsed.
 *
 * The transitional states exist because BMCs report them: a chassis answering
 * "PoweringOn" has already accepted the request, and a caller that read that
 * as "off" would send a second one.
 */
enum PowerState: string
{
    case On = 'on';
    case Off = 'off';
    case PoweringOn = 'powering_on';
    case PoweringOff = 'powering_off';
    case Unknown = 'unknown';

    /**
     * Map a Redfish `PowerState` onto the platform's vocabulary.
     *
     * Anything unrecognised becomes Unknown rather than a guess. Inventing
     * "Off" for a value this adapter has not seen before is how a running
     * machine gets power cycled.
     */
    public static function fromRedfish(?string $state): self
    {
        return match ($state) {
            'On' => self::On,
            'Off' => self::Off,
            'PoweringOn' => self::PoweringOn,
            'PoweringOff' => self::PoweringOff,
            default => self::Unknown,
        };
    }

    /**
     * Read `ipmitool chassis power status`, whose entire output is one line of
     * the form "Chassis Power is on".
     */
    public static function fromIpmiChassisStatus(string $output): self
    {
        $normalised = strtolower(trim($output));

        return match (true) {
            str_contains($normalised, 'chassis power is on') => self::On,
            str_contains($normalised, 'chassis power is off') => self::Off,
            default => self::Unknown,
        };
    }

    public function isOn(): bool
    {
        return $this === self::On;
    }

    /**
     * Whether the chassis has settled, so that a power request would be a new
     * instruction rather than a duplicate of one already in flight.
     */
    public function isSettled(): bool
    {
        return $this === self::On || $this === self::Off;
    }
}
