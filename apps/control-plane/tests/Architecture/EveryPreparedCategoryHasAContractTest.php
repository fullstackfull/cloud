<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Cdn\Domain\Contracts\CdnProvider;
use Lynomia\Modules\EmailHosting\Domain\Contracts\EmailHostingProvider;
use Lynomia\Modules\Notifications\Domain\Contracts\TransactionalEmailProvider;
use Lynomia\Modules\ObjectStorage\Domain\Contracts\ObjectStorageProvider;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The typed seats keep their shape.
 *
 * A prepared product's provider category asks about a set of capabilities;
 * its contract promises a set of methods. The two must be the same set, in
 * both directions, so that the day an adapter is written it answers exactly
 * the questions readiness asks — and so that nothing can be added to a
 * contract that readiness would never discover, or asked about that no
 * contract promises.
 */
final class EveryPreparedCategoryHasAContractTest extends TestCase
{
    /** @var array<string, class-string> */
    private const array CONTRACTS = [
        'cdn' => CdnProvider::class,
        'object_storage' => ObjectStorageProvider::class,
        'email_hosting' => EmailHostingProvider::class,
        'email' => TransactionalEmailProvider::class,
    ];

    /** Methods every contract may carry that are not capabilities. */
    private const array CONTRACTUAL = ['name'];

    #[Test]
    public function every_capability_the_category_asks_about_is_a_method_on_its_contract_and_nothing_else_is(): void
    {
        foreach (self::CONTRACTS as $categoryValue => $contract) {
            $category = ProviderCategory::from($categoryValue);

            $this->assertTrue((new ReflectionClass($contract))->isInterface(), $contract.' must be an interface: a seat, not an adapter');

            $expected = array_map(static fn (string $capability): string => self::camel($capability), $category->capabilities());
            sort($expected);

            $methods = array_values(array_filter(
                array_map(static fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass($contract))->getMethods(ReflectionMethod::IS_PUBLIC)),
                static fn (string $name): bool => ! in_array($name, self::CONTRACTUAL, true),
            ));
            sort($methods);

            $this->assertSame($expected, $methods, sprintf(
                "%s and %s disagree.\n  asked about, not promised: [%s]\n  promised, never asked about: [%s]",
                $category->value,
                $contract,
                implode(', ', array_diff($expected, $methods)),
                implode(', ', array_diff($methods, $expected)),
            ));
        }
    }

    #[Test]
    public function no_prepared_contract_has_an_implementation_that_claims_to_be_real(): void
    {
        // The three product seats have no adapter in this build, by decision:
        // nothing has been observed to write one against. An implementation
        // appearing here without the addendum's verdict changing would be an
        // adapter written from documentation.
        foreach ([CdnProvider::class, ObjectStorageProvider::class, EmailHostingProvider::class] as $contract) {
            foreach (get_declared_classes() as $class) {
                $this->assertFalse(
                    is_subclass_of($class, $contract),
                    sprintf('%s implements %s; the addendum records no real adapter for it.', $class, $contract),
                );
            }
        }
    }

    private static function camel(string $capability): string
    {
        return lcfirst(str_replace('_', '', ucwords($capability, '_')));
    }
}
