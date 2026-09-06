<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Lynomia\Modules\SharedHosting\Domain\DTOs\NodePreflightFacts;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Enums\PreflightRefusalReason;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingPreflightFailedException;
use Lynomia\Modules\SharedHosting\Domain\ValueObjects\PreflightRefusal;
use Lynomia\Modules\SharedHosting\Domain\ValueObjects\PreflightReport;

/**
 * Decides whether a machine may have a control panel installed on it.
 *
 * Every condition here is a REFUSAL and none of them is a warning. The reason
 * is the same in every case, and it is worth stating once at length because
 * the temptation to downgrade one of them arrives on the day somebody is in a
 * hurry:
 *
 * **each of these produces a node that LOOKS installed.** A panel installed
 * onto an unsupported OS finishes with half its stack missing, but WHM
 * answers. A panel installed over an existing httpd inherits a configuration
 * neither product owns, but the node lists accounts. A panel whose hostname is
 * not an FQDN cannot obtain a certificate for its own services, but it serves
 * plain HTTP. A panel whose forward and reverse DNS disagree sends mail that
 * every receiving server silently files as spam. A panel whose ports were
 * already bound starts with a service displaced. A panel with no licence
 * installs perfectly and then refuses to serve. In every case the failure is
 * discovered later, by customers, on a machine that by then has customers on
 * it — and the fix is a migration rather than a reinstall.
 *
 * A warning would be printed once, into an installer log nobody reads, at the
 * one moment when the machine is still cheap to reinstall.
 *
 * All the checks run even after one has failed. Discovering a machine's
 * problems one round trip at a time spends the same window, and usually ends
 * with somebody installing anyway.
 *
 * Reads config('hosting.preflight.*'):
 *
 *   supported_os.<panel>  — map of os id => list of major versions the vendor supports
 *   required_ports.<panel> — ports the panel binds at install time
 *
 * What counts as "not clean" is deliberately NOT a list here. The collector
 * reports the web, database, mail and panel software it actually found on the
 * machine, and anything it found is a refusal: a list in this action could only
 * ever be a list of the packages somebody thought of, and the one that is
 * missing from it is the one that breaks the node.
 */
final readonly class PreflightHostingNode
{
    /**
     * Ports each panel binds when it installs, used when config says nothing.
     *
     * These are the ones whose prior occupant the installer displaces. A
     * machine already serving something on 80 or 3306 is a machine somebody is
     * using.
     *
     * @var array<string, list<int>>
     */
    private const array FALLBACK_PORTS = [
        'cpanel' => [80, 443, 2082, 2083, 2086, 2087, 3306],
        'directadmin' => [80, 443, 2222, 3306],
        'fake' => [],
    ];

    /**
     * Vendor-supported operating systems, used when config says nothing.
     *
     * Deliberately narrow. "It probably works on Debian" is how a fleet ends
     * up with one node nobody can patch.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array FALLBACK_SUPPORTED_OS = [
        'cpanel' => [
            'almalinux' => ['8', '9', '10'],
            'rocky' => ['8', '9', '10'],
            'cloudlinux' => ['8', '9'],
            'ubuntu' => ['22', '24'],
        ],
        'directadmin' => [
            'almalinux' => ['8', '9', '10'],
            'rocky' => ['8', '9', '10'],
            'cloudlinux' => ['8', '9'],
            'debian' => ['11', '12'],
            'ubuntu' => ['22', '24'],
        ],
        'fake' => [],
    ];

    public function execute(HostingPanel $panel, NodePreflightFacts $facts): PreflightReport
    {
        /** @var list<PreflightRefusal> $refusals */
        $refusals = [];

        $supported = $this->supportedOs($panel);
        $versions = $supported[strtolower($facts->osId)] ?? null;

        if ($supported !== [] && ($versions === null || ! $this->versionSupported($facts->osVersion, $versions))) {
            // The vendor ships packages for a fixed list of distributions. On
            // anything else the installer completes far enough to look like it
            // worked and leaves a stack that cannot be patched or upgraded.
            $refusals[] = new PreflightRefusal(
                PreflightRefusalReason::UnsupportedOs,
                $facts->osId.' '.$facts->osVersion,
                $this->describeSupported($supported),
            );
        }

        if ($facts->conflictingServices !== []) {
            /*
             * The panel takes ownership of the web server, the mail stack, the
             * database server and the user database. Installing it over
             * something already doing one of those jobs half-replaces both:
             * the panel's configuration is authoritative but the old service's
             * files, users and cron entries are still there, and every panel
             * update fights them.
             */
            $refusals[] = new PreflightRefusal(
                PreflightRefusalReason::MachineNotClean,
                implode(', ', $facts->conflictingServices),
                'a machine with no web server, database server, mail stack or other panel installed',
            );
        }

        if (! $facts->hostnameIsFqdn()) {
            // The panel signs its own services and stamps outgoing mail with
            // this name. A bare label cannot be certified by any CA, so the
            // node's own control panel is served without a valid certificate
            // and every browser warns the customer on their first login.
            $refusals[] = new PreflightRefusal(
                PreflightRefusalReason::HostnameNotFqdn,
                $facts->hostname === '' ? '(empty)' : $facts->hostname,
                'a fully-qualified domain name such as node1.example.com',
            );
        } elseif ($facts->forwardAddresses === []) {
            /*
             * Checked only once the name is at least well-formed, because
             * "does not resolve" about a bare label is noise on top of the
             * real problem. Licence validation, certificate issuance and mail
             * delivery all resolve this name first; a node whose hostname does
             * not resolve fails all three in ways that look unrelated.
             */
            $refusals[] = new PreflightRefusal(
                PreflightRefusalReason::HostnameDoesNotResolve,
                $facts->hostname.' resolves to nothing',
                'a hostname with an A record pointing at this machine',
            );
        } elseif (! $facts->forwardAndReverseAgree()) {
            /*
             * The single most expensive one to discover late. Receiving mail
             * servers check that the connecting address's PTR resolves back to
             * the name the server greets them with; when it does not, mail is
             * accepted and silently filed as spam. Nothing on the node reports
             * an error, and the customer's complaint is "my contact form
             * stopped working" weeks later.
             */
            $refusals[] = new PreflightRefusal(
                PreflightRefusalReason::DnsMismatch,
                sprintf(
                    '%s resolves to %s, whose reverse name is %s',
                    $facts->hostname,
                    implode(', ', $facts->forwardAddresses),
                    $facts->reverseHostnames === [] ? '(none)' : implode(', ', $facts->reverseHostnames),
                ),
                'forward and reverse DNS that agree on the hostname',
            );
        }

        $conflictingPorts = array_values(array_intersect($facts->boundPorts, $this->requiredPorts($panel)));

        if ($conflictingPorts !== []) {
            // The installer binds these itself. Whatever holds them now is
            // displaced, and on a machine where that is a customer-facing
            // service the displacement is the outage.
            $refusals[] = new PreflightRefusal(
                PreflightRefusalReason::PortsInUse,
                implode(', ', array_map(strval(...), $conflictingPorts)),
                'the panel\'s ports free: '.implode(', ', array_map(strval(...), $this->requiredPorts($panel))),
            );
        }

        if ($panel->requiresLicence() && ($facts->licence === null || ! $facts->licence->valid)) {
            /*
             * cPanel/WHM, DirectAdmin, CloudLinux and LiteSpeed are commercial
             * products. This platform integrates with them and refuses to work
             * without them: there is nothing here, and nothing anywhere in this
             * module, that bypasses, patches or circumvents their licensing,
             * and no configuration flag that enables such a thing.
             *
             * Without a valid licence the install stops cleanly and reports
             * hosting.license_required. It is a refusal rather than a warning
             * because the install would otherwise SUCCEED — the panel goes on,
             * the node comes up, the fleet inventory shows it as ready — and
             * then refuse to serve, so the first thing to discover the missing
             * licence would be a customer's paid order.
             *
             * A licence that could not be checked is treated exactly like a
             * missing one. A check that did not run has not passed.
             */
            $refusals[] = new PreflightRefusal(
                PreflightRefusalReason::LicenceRequired,
                $facts->licence === null
                    ? 'no licence check was performed'
                    : ($facts->licence->state ?? 'invalid').': '.($facts->licence->detail ?? 'no detail'),
                'a valid '.$panel->value.' licence for this machine',
            );
        }

        return new PreflightReport($facts->hostname, $panel, $refusals);
    }

    /**
     * Run the preflight and stop the caller dead if it refused.
     *
     * The throwing form exists so that an installer cannot proceed by
     * forgetting to inspect a returned report.
     *
     * @throws HostingPreflightFailedException
     */
    public function assertReady(HostingPanel $panel, NodePreflightFacts $facts): PreflightReport
    {
        $report = $this->execute($panel, $facts);

        $report->throwIfRefused();

        return $report;
    }

    /**
     * @return array<string, list<string>>
     */
    private function supportedOs(HostingPanel $panel): array
    {
        /** @var array<string, mixed> $configured */
        $configured = config('hosting.preflight.supported_os.'.$panel->value, []);

        if ($configured !== []) {
            /** @var array<string, list<string>> $configured */
            return array_change_key_case($configured);
        }

        return self::FALLBACK_SUPPORTED_OS[$panel->value] ?? [];
    }

    /**
     * @return list<int>
     */
    private function requiredPorts(HostingPanel $panel): array
    {
        /** @var list<mixed> $configured */
        $configured = config('hosting.preflight.required_ports.'.$panel->value, []);

        if ($configured !== []) {
            return array_values(array_map(intval(...), array_filter($configured, is_numeric(...))));
        }

        return self::FALLBACK_PORTS[$panel->value] ?? [];
    }

    /**
     * Compared on the major version only.
     *
     * Vendors support a release series rather than a point release, and
     * pinning to "9.4" would refuse a node that had merely been patched.
     *
     * @param  list<string>  $supported
     */
    private function versionSupported(string $version, array $supported): bool
    {
        $major = explode('.', trim($version))[0];

        foreach ($supported as $candidate) {
            if (explode('.', trim((string) $candidate))[0] === $major) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, list<string>>  $supported
     */
    private function describeSupported(array $supported): string
    {
        $parts = [];

        foreach ($supported as $os => $versions) {
            $parts[] = $os.' '.implode('/', $versions);
        }

        return $parts === [] ? 'a vendor-supported operating system' : implode(', ', $parts);
    }
}
