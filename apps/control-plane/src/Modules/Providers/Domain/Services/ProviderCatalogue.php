<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Services;

use Lynomia\Modules\Providers\Domain\DTOs\CatalogueEntry;
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
            ),
            new CatalogueEntry(
                driver: 'proxmox_backup',
                category: ProviderCategory::Backup,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: false,
                summary: 'Proxmox Backup Server: scheduled archives and restores.',
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
            ),
            new CatalogueEntry(
                driver: 'directadmin',
                category: ProviderCategory::Hosting,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: true,
                summary: 'DirectAdmin shared hosting node.',
            ),
            new CatalogueEntry(
                driver: 'cloudflare',
                category: ProviderCategory::Dns,
                // Cloudflare's API address is not a per-instance choice.
                needsEndpoint: false,
                needsCredential: true,
                needsLicence: false,
                summary: 'Cloudflare authoritative DNS.',
            ),
            new CatalogueEntry(
                driver: 'cloudflare_rdns',
                category: ProviderCategory::ReverseDns,
                needsEndpoint: false,
                needsCredential: true,
                needsLicence: false,
                summary: 'Reverse DNS through Cloudflare.',
            ),
            new CatalogueEntry(
                driver: 'sy_registry',
                category: ProviderCategory::Registrar,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: false,
                summary: 'The .sy registry, reached directly rather than through a reseller.',
            ),
            new CatalogueEntry(
                driver: 'stripe',
                category: ProviderCategory::Payment,
                needsEndpoint: false,
                needsCredential: true,
                needsLicence: false,
                summary: 'Stripe card payments and refunds.',
            ),
            new CatalogueEntry(
                driver: 'ipmi',
                category: ProviderCategory::Bmc,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: false,
                summary: 'IPMI baseboard management: power and boot control.',
            ),
            new CatalogueEntry(
                driver: 'redfish',
                category: ProviderCategory::Bmc,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: false,
                summary: 'Redfish baseboard management: power, boot and inventory.',
            ),
            new CatalogueEntry(
                driver: 'ilo',
                category: ProviderCategory::Bmc,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: false,
                summary: 'HPE iLO baseboard management.',
            ),
            /*
             * The fake is catalogued on purpose.
             *
             * It is the only driver with a connection tester, so leaving it out
             * would mean no environment could exercise a complete provider
             * lifecycle — and a lifecycle that only runs in tests is one whose
             * screens nobody has ever seen work. RegisterProvider refuses it in
             * production, and the tester refuses to be constructed there.
             */
            new CatalogueEntry(
                driver: 'fake',
                // Catalogued as DNS rather than compute, which decides one
                // thing: a DNS provider is somebody else's servers, so
                // rehearsing the lifecycle does not also require a classified
                // machine of ours. The on-machine path has its own coverage
                // through the readiness rules, and making every rehearsal need
                // a rack would mean the rehearsal stopped being run.
                category: ProviderCategory::Dns,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: false,
                summary: 'A controlled provider for rehearsing the onboarding path. Never available in production.',
            ),
            new CatalogueEntry(
                driver: 'fake_bmc',
                // The machine half of the rehearsal: a BMC that answers a
                // connection test and a discovery for a machine that does not
                // exist. Bound to a managed server like any BMC, gated by that
                // server's classification like any BMC.
                category: ProviderCategory::Bmc,
                needsEndpoint: true,
                needsCredential: true,
                needsLicence: false,
                summary: 'A controlled BMC for rehearsing machine onboarding and discovery. Never available in production.',
            ),
        ];
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
     * Named here rather than inferred from the word "fake" in a string, so that
     * a second controlled driver added later is refused by having been thought
     * about, not by having been named carefully.
     *
     * @return list<string>
     */
    public function controlledDrivers(): array
    {
        return ['fake', 'fake_bmc'];
    }
}
