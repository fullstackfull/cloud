<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Backups\Application\Actions\RequestServiceBackup;
use Lynomia\Modules\Dns\Application\Actions\ClaimZone;
use Lynomia\Modules\Domains\Application\Actions\OrderDomainRegistration;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\ProductReadiness\Application\Actions\AssertProductMaySell;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductSoftwareState;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductRequirements;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\SharedHosting\Application\Actions\OrderWordPressSite;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

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
 * software state must match what is in the tree, and each of the three
 * states says something different about the sale.
 *
 *   - `complete`: an action creates the sale, and that action reaches the
 *     sellability guard. A sale nothing guards would outlive the readiness
 *     it was sold on;
 *   - `prepared`: the software is written but the product is not in the
 *     approved launch scope. It may keep the screens and routes that manage
 *     it — deleting working software to express a scope decision loses the
 *     software — but if it has a sale action, that action must reach the
 *     guard, which is what makes the purchase path refuse rather than
 *     merely go unadvertised;
 *   - `readiness_only`: no sale action and no customer route, because there
 *     is nothing behind either.
 *
 * The declaration is reviewed like code, and this is the review.
 */
final class EveryDeclaredCapabilityHasAConsumerTest extends TestCase
{
    /**
     * Where each product's sale is created, for every product that has such
     * an action at all — a prepared product may, and its presence here is
     * not a claim that the product is sellable. Written out by hand so that
     * deleting the action breaks this test at the import.
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

    /**
     * The modules that mean money: an action that reaches one of them has
     * committed the customer to paying, whatever the product is called.
     *
     * @var list<string>
     */
    private const array COMMERCIAL_MODULES = ['Orders', 'Billing', 'Payments'];

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

                    if ($this->createsACommercialCommitment(self::SALE_ACTIONS[$product->value])) {
                        $this->assertTrue(
                            $this->consultsSellability(self::SALE_ACTIONS[$product->value]),
                            sprintf(
                                '%s is billed for by %s, and nothing on that path asks whether the product may be sold. Readiness would fall and the orders would keep being taken.',
                                $product->value,
                                self::SALE_ACTIONS[$product->value],
                            ),
                        );
                    }
                    break;
                case ProductSoftwareState::Prepared:
                    if (array_key_exists($product->value, self::SALE_ACTIONS)) {
                        $this->assertTrue(class_exists(self::SALE_ACTIONS[$product->value]));
                        $this->assertTrue(
                            $this->consultsSellability(self::SALE_ACTIONS[$product->value]),
                            sprintf(
                                '%s is prepared, so it is not in the approved launch scope, and %s can still create it without asking. Route that action through the sellability guard or remove it.',
                                $product->value,
                                self::SALE_ACTIONS[$product->value],
                            ),
                        );
                    }
                    break;
                case ProductSoftwareState::ReadinessOnly:
                    $this->assertArrayNotHasKey($product->value, self::SALE_ACTIONS, sprintf('%s is declared %s and has a sale action; declare it complete or remove the action.', $product->value, $product->softwareState()->value));
                    $this->assertSame([], $this->routesMentioning($product), sprintf('%s is declared %s and a customer route names it.', $product->value, $product->softwareState()->value));
                    break;
            }
        }

        $this->assertSame([], array_diff(array_keys(self::SALE_ACTIONS), array_map(static fn (Product $p): string => $p->value, Product::cases())));
    }

    /**
     * Does this action reach the sellability guard — itself, or through
     * something it is handed?
     *
     * Constructor injection is how this codebase passes collaborators, so
     * for this question the constructor graph is the call graph. PlaceOrder
     * never names the guard: it prices through OrderPricing, and OrderPricing
     * holds it. Walking the graph is what lets that count, and what stops an
     * action being credited with a guard because the words appear in one of
     * its comments.
     *
     * @param  class-string  $class
     */
    private function consultsSellability(string $class): bool
    {
        return isset($this->reachableFrom($class)[AssertProductMaySell::class]);
    }

    /**
     * Does creating this product commit the customer to paying for it?
     *
     * Asked of the tree rather than answered from a list, because the answer
     * decides whether the sellability guard is required, and a list would let
     * a product be quietly excused. An action that reaches Orders, Billing or
     * Payments takes on a commercial commitment and must therefore ask first.
     *
     * DNS and Backups reach none of the three: a zone claim and a backup
     * request are capabilities of a service the customer already bought, and
     * {@see AssertProductMaySell} is explicit that it guards what creates a
     * sale and not what serves one. Guarding them would refuse a scheduled
     * backup of a live machine the day a provider's readiness lapsed, which
     * is the opposite of the intent. Their products are still bound by the
     * prepared branch above: reclassify either one and the guard becomes
     * mandatory for it.
     *
     * @param  class-string  $class
     */
    private function createsACommercialCommitment(string $class): bool
    {
        foreach (array_keys($this->reachableFrom($class)) as $dependency) {
            foreach (self::COMMERCIAL_MODULES as $module) {
                if (str_starts_with($dependency, 'Lynomia\\Modules\\'.$module.'\\')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every first-party class this one is given, transitively, including
     * itself. Cycles terminate on the seen set; third-party and built-in
     * parameters are not followed.
     *
     * @param  class-string  $class
     * @param  array<class-string, true>  $seen
     * @return array<class-string, true>
     */
    private function reachableFrom(string $class, array $seen = []): array
    {
        if (isset($seen[$class]) || ! str_starts_with($class, 'Lynomia\\') || ! class_exists($class)) {
            return $seen;
        }

        $seen[$class] = true;

        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            /** @var class-string $dependency */
            $dependency = $type->getName();
            $seen = $this->reachableFrom($dependency, $seen);
        }

        return $seen;
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
