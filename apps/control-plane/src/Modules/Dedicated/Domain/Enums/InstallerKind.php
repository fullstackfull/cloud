<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Enums;

/**
 * The unattended installer an OS profile drives.
 *
 * Each one takes a completely different configuration language, which is why
 * the profile stores a template and a kind rather than a set of options: there
 * is no common subset of autoinstall, preseed and kickstart worth modelling,
 * and a lowest-common-denominator abstraction over them would silently drop
 * the partitioning directives that decide where a customer's data lives.
 */
enum InstallerKind: string
{
    /** Ubuntu, cloud-init's autoinstall dialect. */
    case Autoinstall = 'autoinstall';

    /** Debian's debian-installer preseed. */
    case Preseed = 'preseed';

    /** AlmaLinux and Rocky Linux, Anaconda's kickstart. */
    case Kickstart = 'kickstart';

    /**
     * The filename the installer expects to be served under on the
     * provisioning VLAN. The kernel command line points at it, so it is part
     * of the contract with the boot server rather than a cosmetic detail.
     */
    public function configFilename(): string
    {
        return match ($this) {
            self::Autoinstall => 'user-data',
            self::Preseed => 'preseed.cfg',
            self::Kickstart => 'ks.cfg',
        };
    }

    /** The kernel argument that tells the installer where to fetch its answers. */
    public function kernelParameter(): string
    {
        return match ($this) {
            self::Autoinstall => 'autoinstall ds=nocloud-net;s=',
            self::Preseed => 'auto=true url=',
            self::Kickstart => 'inst.ks=',
        };
    }
}
