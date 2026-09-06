<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Enums;

/**
 * Why a machine may not have a control panel installed on it.
 *
 * Every case here is a REFUSAL, never a warning, and the reason is the same
 * one each time: a hosting panel takes over the machine it is installed on —
 * its firewall, its mail stack, its web server, its user database, its cron
 * table. Installed onto a host that is already doing something else, or onto
 * one whose identity does not resolve, it does not fail cleanly. It produces a
 * node that LOOKS installed: the panel answers, accounts can be created, and
 * the breakage surfaces later as mail that silently does not deliver,
 * certificates that cannot be issued, or a web server that loses its
 * configuration on the next panel update. By then the node has customers on
 * it, and repairing it means migrating every one of them.
 *
 * A warning would be printed once, into a log nobody reads, at the one moment
 * when the machine is still cheap to reinstall.
 *
 * The error codes are a stable contract: they travel into provisioning results,
 * operator tooling and support tickets, so they are values here rather than
 * strings composed at the throw site.
 */
enum PreflightRefusalReason: string
{
    case UnsupportedOs = 'unsupported_os';
    case MachineNotClean = 'machine_not_clean';
    case HostnameNotFqdn = 'hostname_not_fqdn';
    case HostnameDoesNotResolve = 'hostname_does_not_resolve';
    case DnsMismatch = 'dns_mismatch';
    case PortsInUse = 'ports_in_use';
    case LicenceRequired = 'licence_required';

    /**
     * The stable machine-readable code this refusal is reported under.
     */
    public function errorCode(): string
    {
        return match ($this) {
            self::UnsupportedOs => 'hosting.unsupported_os',
            self::MachineNotClean => 'hosting.machine_not_clean',
            self::HostnameNotFqdn => 'hosting.hostname_not_fqdn',
            self::HostnameDoesNotResolve => 'hosting.hostname_does_not_resolve',
            self::DnsMismatch => 'hosting.dns_mismatch',
            self::PortsInUse => 'hosting.ports_in_use',
            // Spelled out in docs/shared-hosting.md and reported verbatim by
            // the installers. cPanel/WHM, DirectAdmin, CloudLinux and LiteSpeed
            // are commercial products; without a valid licence the platform
            // stops here and says so. It never proceeds, never patches and
            // never works around the vendor's licensing.
            self::LicenceRequired => 'hosting.license_required',
        };
    }

    /**
     * Why this specific condition cannot be downgraded to a warning.
     *
     * Carried on the refusal so that the operator reading a failed preflight
     * is told what the half-broken node would have looked like, rather than
     * being left to decide that the check is being fussy.
     */
    public function consequence(): string
    {
        return match ($this) {
            self::UnsupportedOs => 'the vendor ships no packages for this OS, so the installer would leave a partial stack behind',
            self::MachineNotClean => 'an existing web, database or panel stack would be half-replaced, and neither would work afterwards',
            self::HostnameNotFqdn => 'the panel signs its own services and outgoing mail with the hostname, and a bare name cannot be certified',
            self::HostnameDoesNotResolve => 'licence checks, certificate issuance and mail delivery all resolve the hostname first',
            self::DnsMismatch => 'receiving mail servers reject a host whose forward and reverse names disagree, so outbound mail silently fails',
            self::PortsInUse => 'the panel binds these ports at install time and the service that already holds them would be displaced',
            self::LicenceRequired => 'the panel would install and then refuse to serve, leaving a node that looks ready and takes no accounts',
        };
    }
}
