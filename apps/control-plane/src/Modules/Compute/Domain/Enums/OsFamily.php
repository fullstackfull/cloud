<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Enums;

/**
 * The guest operating system family a template installs.
 *
 * It is an enum rather than free text because two decisions read it: which
 * ostype the hypervisor is told to expect, and whether cloud-init or
 * cloudbase-init configures the first boot. A typo in a string column would
 * produce a machine that boots with no network and no key.
 */
enum OsFamily: string
{
    case Debian = 'debian';
    case Ubuntu = 'ubuntu';
    case Rocky = 'rocky';
    case Alma = 'alma';
    case Windows = 'windows';
    case Other = 'other';

    public function isWindows(): bool
    {
        return $this === self::Windows;
    }

    /**
     * The Proxmox ostype hint. It only selects sensible device defaults, but
     * getting it wrong on Windows costs measurable guest performance.
     */
    public function proxmoxOsType(): string
    {
        return $this->isWindows() ? 'win11' : 'l26';
    }
}
