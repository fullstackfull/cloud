<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Backups\Infrastructure\Providers\ProxmoxBackupProvider;
use Lynomia\Modules\Domains\Domain\Enums\RegistrarCapability;
use Lynomia\Modules\Domains\Infrastructure\Providers\SyRegistryProvider;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\Requirement;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductSoftwareState;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductRequirements;
use Lynomia\Modules\Providers\Domain\DTOs\CatalogueEntry;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * A product is not complete on the strength of its own simulator.
 *
 * ---------------------------------------------------------------------------
 * The invariant
 * ---------------------------------------------------------------------------
 *
 * A product whose software state is `complete` requires things of providers.
 * For each of those requirements there must be at least one adapter in this
 * repository that is not a controlled simulator and that can actually perform
 * every capability the requirement names.
 *
 * Three things that look like satisfying that and do not:
 *
 *  - **A controlled driver.** Every category has a simulator and every
 *    simulator answers almost everything, so a check that counted them would
 *    pass for all sixteen products. The exclusion is asserted to be
 *    load-bearing below, by showing which products only pass with the
 *    simulators counted in.
 *  - **A class existing.** `SyRegistryProvider` implements the entire
 *    registrar contract. Its `supports()` answers false for every capability
 *    and every operation throws.
 *  - **A catalogue entry existing.** Which is the same mistake one level up,
 *    and the one this gate was written for: a check asking "is there a
 *    non-controlled driver in this category?" reads `sy_registry` and calls
 *    Domains ready to sell.
 *
 * So the question is asked per capability, of the adapter, through the
 * capabilities the catalogue declares beside each driver.
 *
 * ---------------------------------------------------------------------------
 * What this gate does not say
 * ---------------------------------------------------------------------------
 *
 * It is one-directional on purpose. A `prepared` product may well have a real
 * adapter for everything it needs — being outside the approved launch scope is
 * a decision about what Lynomia sells, not only a fact about what it can do.
 * What may not happen is the reverse: a product declared complete, offered for
 * sale, with nothing behind it but a fake.
 */
final class EveryCompleteProductHasARealAdapterTest extends TestCase
{
    private ProductRequirements $requirements;

    private ProviderCatalogue $catalogue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requirements = new ProductRequirements;
        $this->catalogue = new ProviderCatalogue;
    }

    #[Test]
    public function every_complete_product_has_a_production_capable_adapter_for_everything_it_requires(): void
    {
        $complete = array_values(array_filter(
            Product::cases(),
            static fn (Product $p): bool => $p->softwareState() === ProductSoftwareState::Complete,
        ));

        // If this ever empties, the gate below passes by having nothing to
        // check, which is the one way a green build here would mean nothing.
        $this->assertNotEmpty($complete);

        foreach ($complete as $product) {
            $this->assertSame(
                [],
                $this->unmetRequirements($product),
                sprintf(
                    '%s is declared complete and no production-capable adapter in this repository can perform what it requires. '
                    .'Either an adapter is missing, or the declaration is wrong.',
                    $product->value,
                ),
            );
        }
    }

    #[Test]
    public function the_products_outside_the_launch_scope_are_the_ones_only_a_simulator_could_carry(): void
    {
        $carriedByFakesAlone = [];

        foreach (Product::cases() as $product) {
            $real = $this->unmetRequirements($product);
            $withSimulators = $this->unmetRequirements($product, countControlled: true);

            if ($real !== [] && $withSimulators === []) {
                $carriedByFakesAlone[] = $product->value;
            }
        }

        /*
         * The exclusion of controlled drivers doing its work, named.
         *
         * WordPress needs an installer that can install and report a version:
         * `WordPressInstaller` is implemented by `FakeHostingProvider` and by
         * nothing else. Domains needs a registrar that can search, register,
         * renew and transfer: `fake_registrar` does all of it and
         * `sy_registry` does none of it.
         *
         * Both products are fully built and fully tested against those
         * simulators, and both are `prepared`. This assertion is what makes
         * the distinction visible rather than incidental — and if a real
         * adapter arrives for either, this list shrinks and the failure it
         * causes is the reminder to revisit the product's software state.
         */
        $this->assertSame(['wordpress', 'domains'], $carriedByFakesAlone);

        foreach ($carriedByFakesAlone as $slug) {
            $this->assertNotSame(
                ProductSoftwareState::Complete,
                Product::from($slug)->softwareState(),
                sprintf('%s can only be carried by a simulator and is declared complete.', $slug),
            );
        }
    }

    #[Test]
    public function a_non_controlled_driver_that_can_do_nothing_satisfies_nothing(): void
    {
        /*
         * The false positive this gate exists to refuse, in isolation.
         *
         * A real, catalogued, non-controlled adapter in exactly the right
         * category, which declares no capabilities. Every weaker form of this
         * check — is there a driver, is there a non-controlled driver, is
         * there a class implementing the contract — passes it.
         */
        $exists = new CatalogueEntry(
            driver: 'a_registrar_we_have_no_contract_with',
            category: ProviderCategory::Registrar,
            needsEndpoint: true,
            needsCredential: true,
            needsLicence: true,
            summary: 'An adapter with nothing behind it.',
            capabilities: [],
        );

        $this->assertFalse($exists->canPerform(['register']));
        $this->assertTrue($exists->canPerform([]));

        // And the same question of a requirement that names real work.
        $registration = new Requirement(ProviderCategory::Registrar, ['search', 'register']);
        $this->assertFalse($exists->canPerform($registration->capabilities));
    }

    #[Test]
    public function the_sy_registry_entry_and_the_sy_registry_adapter_agree_that_it_can_do_nothing(): void
    {
        $entry = $this->entryFor('sy_registry');

        // Real, not a simulator. This is what makes it dangerous to a weaker
        // check rather than merely absent from one.
        $this->assertFalse($entry->controlled);
        $this->assertSame([], $entry->capabilities);

        // And the adapter says the same thing, so the declaration is not a
        // separate opinion about the same class.
        $adapter = new SyRegistryProvider;

        foreach (RegistrarCapability::cases() as $capability) {
            $this->assertFalse(
                $adapter->supports($capability),
                sprintf('sy_registry declares no capabilities and its adapter supports %s.', $capability->value),
            );
        }

        $this->assertSame([], $adapter->supportedTlds());
    }

    #[Test]
    public function the_backup_entry_and_the_backup_adapter_agree_that_it_cannot_be_asked_to_verify(): void
    {
        $entry = $this->entryFor('proxmox_backup');

        $this->assertFalse($entry->controlled);
        $this->assertNotContains('verify', $entry->capabilities);

        // What it does have is the verdict, which is what the product
        // requires — and file-level access, which it does not implement, is
        // absent for the same reason `verify` is.
        $this->assertContains('verification_verdict', $entry->capabilities);
        $this->assertNotContains('file_browse', $entry->capabilities);
        $this->assertNotContains('file_restore', $entry->capabilities);

        /*
         * Instantiated without its constructor because the question is pure:
         * supportsVerification() is a statement about the Proxmox API and
         * touches neither the connection nor the redactor. Building a real
         * connection here would make an architecture test need an endpoint.
         */
        $adapter = (new ReflectionClass(ProxmoxBackupProvider::class))->newInstanceWithoutConstructor();

        $this->assertFalse($adapter->supportsVerification());
    }

    #[Test]
    public function nothing_outside_the_simulator_can_install_wordpress(): void
    {
        // No catalogue entry at all, rather than one that declares nothing.
        $this->assertSame(
            [],
            $this->realEntriesIn(ProviderCategory::WordPressInstaller),
            'A production-capable WordPress installer is catalogued. Revisit the WordPress software state.',
        );

        /*
         * And the contract confirms it from the other direction.
         *
         * Read off the source rather than asked of the autoloader: nothing has
         * loaded the provider classes at this point, so `get_declared_classes`
         * would answer "no implementors" and this assertion would pass by
         * finding nothing — which is the failure mode an architecture test can
         * least afford.
         */
        $implementors = $this->classesImplementing('WordPressInstaller');

        $this->assertNotEmpty($implementors, 'The source scan found no WordPressInstaller at all, so it is broken.');

        foreach ($implementors as $class) {
            $this->assertStringStartsWith(
                'Fake',
                $class,
                sprintf('%s implements WordPressInstaller and is not a simulator.', $class),
            );
        }
    }

    /**
     * Every class in src/ whose `implements` clause names this interface.
     *
     * @return list<string>
     */
    private function classesImplementing(string $interface): array
    {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src'));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)[^{]*\bimplements\b([^{]*)/m', $source, $match) !== 1) {
                continue;
            }

            if (in_array($interface, array_map('trim', explode(',', $match[2])), true)) {
                $found[] = $match[1];
            }
        }

        sort($found);

        return $found;
    }

    #[Test]
    public function no_entry_claims_a_capability_its_category_never_asks_about(): void
    {
        foreach ($this->catalogue->entries() as $entry) {
            $this->assertSame(
                [],
                array_values(array_diff($entry->capabilities, $entry->category->capabilities())),
                sprintf(
                    '%s declares a capability that %s does not ask about. A capability nothing asks about is never '
                    .'discovered, never tested and never read, so declaring it here satisfies a requirement on paper only.',
                    $entry->driver,
                    $entry->category->value,
                ),
            );
        }
    }

    /**
     * Which of a product's requirements no adapter can meet.
     *
     * @return list<string>
     */
    private function unmetRequirements(Product $product, bool $countControlled = false): array
    {
        $unmet = [];

        foreach ($this->requirements->for($product) as $requirement) {
            if (! $this->someAdapterCanPerform($requirement, $countControlled)) {
                $unmet[] = sprintf('%s: %s', $requirement->category->value, implode(', ', $requirement->capabilities));
            }
        }

        return $unmet;
    }

    private function someAdapterCanPerform(Requirement $requirement, bool $countControlled): bool
    {
        foreach ($this->catalogue->entries() as $entry) {
            if ($entry->category !== $requirement->category) {
                continue;
            }

            if ($entry->controlled && ! $countControlled) {
                continue;
            }

            if ($entry->canPerform($requirement->capabilities)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<CatalogueEntry>
     */
    private function realEntriesIn(ProviderCategory $category): array
    {
        return array_values(array_filter(
            $this->catalogue->entries(),
            static fn (CatalogueEntry $entry): bool => $entry->category === $category && ! $entry->controlled,
        ));
    }

    private function entryFor(string $driver): CatalogueEntry
    {
        foreach ($this->catalogue->entries() as $entry) {
            if ($entry->driver === $driver) {
                return $entry;
            }
        }

        $this->fail(sprintf('The catalogue has no %s entry, and this gate is written about it.', $driver));
    }
}
