<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Backups\Application\Actions\RequestServiceBackup;
use Lynomia\Modules\Dns\Application\Actions\ClaimZone;
use Lynomia\Modules\Domains\Application\Actions\OrderDomainRegistration;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductSoftwareState;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductRequirements;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\SharedHosting\Application\Actions\OrderWordPressSite;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The capability engine, kept honest in both directions.
 *
 * A capability is a question a connection test asks a provider. The answer
 * is worth recording only if something reads it — a product requirement,
 * which is where every consumer in this platform is declared. So:
 *
 *   - every capability a category declares is named by some requirement,
 *     required or optional. A declared-but-unconsumed capability is a
 *     "supported" the platform records about itself for nobody;
 *   - every capability a requirement names is one its category declares.
 *     A consumer asking for a question nobody asks would never be
 *     satisfied and would never say why;
 *   - every category some product requires has a question set to ask, or
 *     discovery would probe for nothing and readiness would judge on it.
 *
 * The third gate the addendum asked for — a catalogued driver with no
 * implementation behind it — is TheCatalogueOnlyClaimsWhatExistsTest, and
 * it is referenced here so that nobody looks for it in this file and
 * concludes it does not exist.
 *
 * Also here, because it is the same kind of fact: a product's declared
 * software state must match what is in the tree. A `complete` product has
 * an action that creates a sale; a `readiness_only` product has none and no
 * route. The declaration is reviewed like code, and this is the review.
 */
final class EveryDeclaredCapabilityHasAConsumerTest extends TestCase
{
    /**
     * Where each complete product's sale is created. Written out by hand so
     * that deleting the action breaks this test at the import.
     *
     * @var array<string, class-string>
     */
    private const array SALE_ACTIONS = [
        'vps' => PlaceOrder::class,
        'dedicated' => PlaceOrder::class,
        'shared_hosting' => PlaceOrder::class,
        'wordpress' => OrderWordPressSite::class,
        'domains' => OrderDomainRegistration::class,
        'dns' => ClaimZone::class,
        'backups' => RequestServiceBackup::class,
    ];

    private ProductRequirements $requirements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requirements = new ProductRequirements;
    }

    #[Test]
    public function every_capability_a_category_declares_is_consumed_by_a_product_requirement(): void
    {
        $consumed = [];
        $rows = $this->requirements->shared();
        foreach (Product::cases() as $product) {
            $rows = [...$rows, ...$this->requirements->own($product)];
        }
        foreach ($rows as $requirement) {
            $key = $requirement->category->value;
            $consumed[$key] = [...($consumed[$key] ?? []), ...$requirement->capabilities, ...$requirement->optional];
        }

        $orphans = [];

        foreach (ProviderCategory::cases() as $category) {
            foreach ($category->capabilities() as $capability) {
                if (! in_array($capability, $consumed[$category->value] ?? [], strict: true)) {
                    $orphans[] = sprintf('%s.%s', $category->value, $capability);
                }
            }
        }

        $this->assertSame([], $orphans, sprintf(
            "These capabilities are asked about and nothing consumes the answer:\n  %s\n".
            'Name them in a product requirement (required or optional) or remove them from the category.',
            implode("\n  ", $orphans),
        ));
    }

    #[Test]
    public function every_capability_a_requirement_names_is_one_its_category_declares(): void
    {
        $unknown = [];

        foreach (Product::cases() as $product) {
            foreach ($this->requirements->for($product) as $requirement) {
                foreach ([...$requirement->capabilities, ...$requirement->optional] as $capability) {
                    if (! in_array($capability, $requirement->category->capabilities(), strict: true)) {
                        $unknown[] = sprintf('%s asks %s for %s', $product->value, $requirement->category->value, $capability);
                    }
                }
            }
        }

        $this->assertSame([], $unknown, "These requirements name a capability nobody discovers:\n  ".implode("\n  ", $unknown));
    }

    #[Test]
    public function a_required_capability_is_never_also_optional(): void
    {
        foreach (Product::cases() as $product) {
            foreach ($this->requirements->for($product) as $requirement) {
                $this->assertSame(
                    [],
                    array_intersect($requirement->capabilities, $requirement->optional),
                    sprintf('%s lists a %s capability as both required and optional.', $product->value, $requirement->category->value),
                );
            }
        }
    }

    #[Test]
    public function every_category_a_product_requires_has_something_to_discover(): void
    {
        foreach (Product::cases() as $product) {
            foreach ($this->requirements->for($product) as $requirement) {
                $this->assertNotEmpty($requirement->category->capabilities(), sprintf('%s requires %s, which asks no questions.', $product->value, $requirement->category->value));
            }
        }
    }

    #[Test]
    public function every_catalogued_driver_is_checked_against_its_adapter_elsewhere(): void
    {
        // The catalogue gate lives beside the catalogue. This asserts only
        // that it is still there, so the three gates the addendum named are
        // all findable from this one file.
        $this->assertFileExists(__DIR__.'/../Feature/Providers/TheCatalogueOnlyClaimsWhatExistsTest.php');
        $this->assertNotEmpty((new ProviderCatalogue)->entries());
    }

    #[Test]
    public function a_complete_product_has_an_action_that_creates_its_sale_and_a_readiness_only_one_has_none(): void
    {
        foreach (Product::cases() as $product) {
            switch ($product->softwareState()) {
                case ProductSoftwareState::Complete:
                    $this->assertArrayHasKey($product->value, self::SALE_ACTIONS, sprintf('%s is declared complete and no sale action is mapped for it.', $product->value));
                    $this->assertTrue(class_exists(self::SALE_ACTIONS[$product->value]));
                    break;
                case ProductSoftwareState::Prepared:
                case ProductSoftwareState::ReadinessOnly:
                    $this->assertArrayNotHasKey($product->value, self::SALE_ACTIONS, sprintf('%s is declared %s and has a sale action; declare it complete or remove the action.', $product->value, $product->softwareState()->value));
                    $this->assertSame([], $this->routesMentioning($product), sprintf('%s is declared %s and a customer route names it.', $product->value, $product->softwareState()->value));
                    break;
            }
        }

        $this->assertSame([], array_diff(array_keys(self::SALE_ACTIONS), array_map(static fn (Product $p): string => $p->value, Product::cases())));
    }

    /**
     * @return list<string>
     */
    private function routesMentioning(Product $product): array
    {
        $needle = str_replace('_', '-', $product->value);
        $hits = [];

        foreach (glob(__DIR__.'/../../routes/v1/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), "'".$needle)) {
                $hits[] = basename($file);
            }
        }

        return $hits;
    }
}
