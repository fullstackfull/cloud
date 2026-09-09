<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use Lynomia\Modules\Backups\Infrastructure\Providers\ProxmoxBackupProvider;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxComputeProvider;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\IloDedicatedProvider;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\IpmiDedicatedProvider;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\RedfishDedicatedProvider;
use Lynomia\Modules\Dns\Infrastructure\Providers\CloudflareDnsProvider;
use Lynomia\Modules\Domains\Infrastructure\Providers\SyRegistryProvider;
use Lynomia\Modules\Ipam\Infrastructure\Providers\CloudflareReverseDnsProvider;
use Lynomia\Modules\Payments\Infrastructure\Providers\StripePaymentProvider;
use Lynomia\Modules\Providers\Domain\DTOs\CatalogueEntry;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\Providers\Infrastructure\ConnectionTesterFactory;
use Lynomia\Modules\Providers\Infrastructure\Testers\FakeConnectionTester;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\CpanelHostingProvider;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\DirectAdminHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The catalogue is a promise, and this is what keeps it one.
 *
 * ---------------------------------------------------------------------------
 * Why the map below is written out by hand
 * ---------------------------------------------------------------------------
 *
 * Because the failure being prevented is a catalogue entry with nothing behind
 * it, and any check clever enough to find the adapter by convention would also
 * be clever enough to be fooled by a class named to match. Naming the class
 * explicitly means deleting an adapter breaks this test at the import, before
 * PHPUnit even runs — which is the earliest anybody could be told.
 *
 * The map is also documentation. "Which class actually talks to cPanel" is a
 * question somebody asks in the first week, and this is the answer.
 */
final class TheCatalogueOnlyClaimsWhatExistsTest extends TestCase
{
    /**
     * Every catalogued driver and the class that does the work.
     *
     * @var array<string, class-string>
     */
    private const array ADAPTERS = [
        'proxmox' => ProxmoxComputeProvider::class,
        'proxmox_backup' => ProxmoxBackupProvider::class,
        'cpanel' => CpanelHostingProvider::class,
        'directadmin' => DirectAdminHostingProvider::class,
        'cloudflare' => CloudflareDnsProvider::class,
        'cloudflare_rdns' => CloudflareReverseDnsProvider::class,
        'sy_registry' => SyRegistryProvider::class,
        'stripe' => StripePaymentProvider::class,
        'ipmi' => IpmiDedicatedProvider::class,
        'redfish' => RedfishDedicatedProvider::class,
        'ilo' => IloDedicatedProvider::class,
        'fake' => FakeConnectionTester::class,
        'fake_bmc' => FakeConnectionTester::class,
    ];

    private ProviderCatalogue $catalogue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalogue = new ProviderCatalogue;
    }

    #[Test]
    public function every_catalogued_driver_has_something_behind_it(): void
    {
        foreach ($this->catalogue->entries() as $entry) {
            $this->assertArrayHasKey(
                $entry->driver,
                self::ADAPTERS,
                sprintf(
                    'The catalogue offers %s and this test does not know what implements it. '
                    .'A catalogue entry with no class is a vendor the platform advertises to itself.',
                    $entry->driver,
                ),
            );

            $this->assertTrue(class_exists(self::ADAPTERS[$entry->driver]));
        }
    }

    #[Test]
    public function the_map_has_not_outlived_the_catalogue(): void
    {
        // The other direction, and the one that rots silently: an adapter
        // removed from the catalogue leaves an entry here claiming a driver
        // operators can no longer choose.
        $catalogued = array_map(static fn (CatalogueEntry $entry): string => $entry->driver, $this->catalogue->entries());

        $this->assertSame([], array_diff(array_keys(self::ADAPTERS), $catalogued));
    }

    #[Test]
    public function no_driver_is_catalogued_twice(): void
    {
        // Two entries for one driver means the one that wins depends on
        // iteration order, and a screen and a validator could each pick a
        // different set of requirements for the same thing.
        $drivers = array_map(static fn (CatalogueEntry $entry): string => $entry->driver, $this->catalogue->entries());

        $this->assertSame(array_unique($drivers), $drivers);
    }

    #[Test]
    public function every_registered_tester_is_a_driver_the_catalogue_knows(): void
    {
        /*
         * The direction that would otherwise let a driver in through the back.
         * A tester registered under a key the catalogue has never heard of
         * would be a provider that can be connection-tested and never
         * registered — reachable only by someone constructing the row by hand.
         */
        $testers = app(ConnectionTesterFactory::class);

        foreach ($testers->drivers() as $driver) {
            $this->assertNotNull(
                $this->catalogue->find($driver),
                sprintf('A connection tester is registered for %s and the catalogue does not offer it.', $driver),
            );
        }
    }

    #[Test]
    public function the_controlled_drivers_are_all_real_entries(): void
    {
        foreach ($this->catalogue->controlledDrivers() as $driver) {
            $this->assertNotNull(
                $this->catalogue->find($driver),
                sprintf('%s is refused in production and is not in the catalogue, so the refusal guards nothing.', $driver),
            );
        }
    }

    #[Test]
    public function every_catalogued_driver_serves_a_category_with_something_to_discover(): void
    {
        // Each entry's category has to be one the platform actually asks
        // questions about, or capability discovery would probe for things
        // nobody consumes and record answers no screen reads.
        foreach ($this->catalogue->entries() as $entry) {
            $this->assertNotEmpty(
                $entry->category->capabilities(),
                sprintf('%s is catalogued as %s, a category with no capabilities to discover.', $entry->driver, $entry->category->value),
            );

        }
    }
}
