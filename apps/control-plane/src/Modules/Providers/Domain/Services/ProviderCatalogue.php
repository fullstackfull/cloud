<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Services;

use Lynomia\Modules\Providers\Domain\DTOs\CatalogueEntry;
use Lynomia\Modules\Providers\Domain\Enums\ControlledDriver;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Infrastructure\ConnectionTesterFactory;

/**
 * Everything Lynomia has an adapter for, and nothing else.
 *
 * ---------------------------------------------------------------------------
 * Why this is a list in the source and not a table
 * ---------------------------------------------------------------------------
 *
 * Because an entry is only true if a class exists to back it. A catalogue in
 * the database can be edited into claiming the platform supports a vendor it
 * has never spoken to, and the first person to find out is a customer whose
 * order sat in a queue. A catalogue in the source cannot drift from the
 * adapters beside it without somebody editing this file, and
 * TheCatalogueOnlyClaimsWhatExistsTest fails the build if it does.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately absent
 * ---------------------------------------------------------------------------
 *
 * Every driver here has an adapter. Almost none of them has a connection
 * tester, and that is not an oversight — a tester is written against a real
 * endpoint, and Phase 30B ended with no real endpoint available and a NO GO
 * recorded. So the catalogue describes what the platform can do, and
 * {@see ConnectionTesterFactory::handles()}
 * answers separately whether an instance of it can currently be proven. The
 * readiness engine treats "cannot be tested" as a configuration blocker, which
 * is the true statement: nothing is wrong with the provider, something is
 * missing here.
 */
final readonly class ProviderCatalogue
{
    /**
     * @return list<CatalogueEntry>
     */
    public function entries(): array
    {
        return [
            new CatalogueEntry(
                driver: 'proxmox',
                category: ProviderCategory::Compute,
                needsEndpoint: true,
                needsCredential: true,
                // Proxmox VE is open source. A support subscription is a
                // commercial choice and not a condition of running it, so
                // demanding a licence row here would block a legitimate setup.
                needsLicence: false,
                summary: 'Proxmox VE cluster: virtual machines, snapshots and consoles.',
                /*
                 * Everything the compute category asks except GPU passthrough,
                 * which ComputeProvider does not model at all: CreateVmRequest
                 * carries no device, ResizeVmRequest cannot add one, and no
                 * adapter reads one. Templates are here because the adapter
                 * installs from one — `import-from=` on the disk it builds.
                 * Inventory sync is here because listNodes() reads each
                 * online node's storage pools.
                 */
                capabilities: ['create', 'start', 'stop', 'reboot', 'resize', 'reinstall', 'suspend', 'unsuspend', 'console', 'destroy', 'templates', 'task_polling', 'inventory_sync'],
            ),
            new CatalogueEntry(
                driver: 'proxmox_backup',
                category: ProviderCategory::Backup,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: false,
                summary: 'Proxmox Backup Server: scheduled archives and restores.',
                /*
                 * `verify` is absent and that is a statement about Proxmox
                 * rather than about this adapter: a PBS verification runs on
                 * the backup server on its own schedule and the hypervisor API
                 * has no endpoint that starts one, which is why
                 * supportsVerification() answers false. The verdict is here,
                 * because listBackups() reports it per archive and the
                 * inventory sweep adopts it.
                 *
                 * `file_browse` and `file_restore` are absent because this
                 * adapter does not implement FileLevelBackupProvider.
                 */
                capabilities: ['create', 'restore', 'delete', 'retention', 'verification_verdict'],
            ),
            new CatalogueEntry(
                driver: 'cpanel',
                category: ProviderCategory::Hosting,
                needsEndpoint: true,
                needsCredential: true,
                // A cPanel installation without a licence stops serving. This
                // is the case the licence centre exists for.
                needsLicence: true,
                summary: 'cPanel/WHM shared hosting node.',
                capabilities: ['create_account', 'suspend', 'unsuspend', 'terminate', 'sso', 'change_package', 'usage'],
            ),
            new CatalogueEntry(
                driver: 'directadmin',
                category: ProviderCategory::Hosting,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: true,
                summary: 'DirectAdmin shared hosting node.',
                capabilities: ['create_account', 'suspend', 'unsuspend', 'terminate', 'sso', 'change_package', 'usage'],
            ),
            new CatalogueEntry(
                driver: 'cloudflare',
                category: ProviderCategory::Dns,
                // Cloudflare's API address is not a per-instance choice.
                needsEndpoint: false,
                needsCredential: true,
                needsLicence: false,
                summary: 'Cloudflare authoritative DNS.',
                capabilities: ['create_zone', 'delete_zone', 'records', 'reconcile'],
            ),
            new CatalogueEntry(
                driver: 'cloudflare_rdns',
                category: ProviderCategory::ReverseDns,
                needsEndpoint: false,
                needsCredential: true,
                needsLicence: false,
                summary: 'Reverse DNS through Cloudflare.',
                capabilities: ['set_ptr', 'clear_ptr'],
            ),
            new CatalogueEntry(
                driver: 'sy_registry',
                category: ProviderCategory::Registrar,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: false,
                summary: 'The .sy registry, reached directly rather than through a reseller.',
                /*
                 * Nothing, and the empty list is the entry's whole point.
                 *
                 * SyRegistryProvider is a real class implementing the whole
                 * registrar contract. Its supports() answers false for every
                 * capability, its supportedTlds() is empty, and every
                 * operation throws RegistrarNotAvailableException — the .sy
                 * registry publishes no reseller API and Lynomia holds no
                 * accreditation, so there is nothing for the adapter to call.
                 *
                 * It stays catalogued because the platform genuinely has the
                 * adapter and the day a contract exists it is where the work
                 * goes. Declaring nothing is what stops it being counted as
                 * the production-capable registrar the Domains product would
                 * need, which is exactly the false green a driver-exists check
                 * would have produced.
                 */
                capabilities: [],
            ),
            new CatalogueEntry(
                driver: 'stripe',
                category: ProviderCategory::Payment,
                needsEndpoint: false,
                needsCredential: true,
                needsLicence: false,
                summary: 'Stripe card payments and refunds.',
                capabilities: ['charge', 'refund', 'webhook', 'currencies'],
            ),
            new CatalogueEntry(
                driver: 'smtp',
                category: ProviderCategory::Email,
                // The relay is a deployment setting (MAIL_HOST), not an
                // address the Control Center dials — the endpoint policy
                // speaks HTTPS to providers and a relay speaks SMTP. The row
                // exists so readiness has something to point at. No
                // connection tester exists and none can be written honestly
                // against a mailer that may be `log`, so the driver is
                // untestable and a row for it stays blocked on credentials.
                needsEndpoint: false,
                needsCredential: true,
                needsLicence: false,
                summary: 'Transactional mail through the deployment\'s own mail transport. Untestable from here.',
                capabilities: ['send'],
            ),
            new CatalogueEntry(
                driver: 'ipmi',
                category: ProviderCategory::Bmc,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: false,
                summary: 'IPMI baseboard management: power and boot control.',
                capabilities: ['inventory', 'power_state', 'power_control', 'boot_override', 'firmware'],
            ),
            new CatalogueEntry(
                driver: 'redfish',
                category: ProviderCategory::Bmc,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: false,
                summary: 'Redfish baseboard management: power, boot and inventory.',
                capabilities: ['inventory', 'power_state', 'power_control', 'boot_override', 'firmware'],
            ),
            new CatalogueEntry(
                driver: 'ilo',
                category: ProviderCategory::Bmc,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: false,
                summary: 'HPE iLO baseboard management.',
                // Inherits every operation from the Redfish adapter, which it
                // extends to paper over iLO's deviations from the spec.
                capabilities: ['inventory', 'power_state', 'power_control', 'boot_override', 'firmware'],
            ),
            ...$this->controlled(),
        ];
    }

    /**
     * Every driver that exists for rehearsal, built from the enum that
     * declares them.
     *
     * Written this way rather than as nine more literals because the list and
     * the refusal have to be the same list. The fake is catalogued on purpose
     * — without it no environment could exercise a complete provider
     * lifecycle, and a lifecycle that only runs in tests is one whose screens
     * nobody has ever seen work — and registration refuses every one of them in
     * production, as does each simulator's own constructor.
     *
     * @return list<CatalogueEntry>
     */
    private function controlled(): array
    {
        return array_map(
            static fn (ControlledDriver $driver): CatalogueEntry => CatalogueEntry::controlled($driver),
            ControlledDriver::cases(),
        );
    }

    public function find(string $driver): ?CatalogueEntry
    {
        foreach ($this->entries() as $entry) {
            if ($entry->driver === $driver) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Drivers that must never be registered in production.
     *
     * Derived from the entries rather than listed beside them. The earlier
     * version returned a literal `['fake', 'fake_bmc']`, which could not
     * disagree with the catalogue — a test caught that — but which also could
     * not grow when a simulator did. It stayed at two while eight stateful
     * simulators sat behind the per-family factories, unknown to the provider
     * registry, and the reference estate could not rehearse three of the five
     * dependencies it declares.
     *
     * @return list<string>
     */
    public function controlledDrivers(): array
    {
        return array_values(array_map(
            static fn (CatalogueEntry $entry): string => $entry->driver,
            array_filter($this->entries(), static fn (CatalogueEntry $entry): bool => $entry->controlled),
        ));
    }
}
