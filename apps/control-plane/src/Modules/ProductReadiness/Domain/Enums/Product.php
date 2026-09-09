<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\Enums;

/**
 * The things Lynomia sells, as the readiness engine sees them.
 *
 * Not the catalogue's product kinds: the catalogue lists what has a price,
 * and this lists what has to WORK — which includes lines sold through another
 * product's price (a WordPress site is a hosting plan with an installer) and
 * lines nobody has priced yet. A product missing from here cannot be declared
 * ready to sell, which is the safe default.
 */
enum Product: string
{
    case Vps = 'vps';
    case Dedicated = 'dedicated';
    case SharedHosting = 'shared_hosting';
    case WordPress = 'wordpress';
    case Domains = 'domains';
    case Dns = 'dns';
    case Backups = 'backups';

    /**
     * What has to be sellable before this can be.
     *
     * A dependency caps the dependent: a WordPress site is installed into a
     * hosting account, so the WordPress line can never be readier than
     * shared hosting is. The engine propagates the dependency's blocker
     * rather than inventing one of its own.
     *
     * @return list<self>
     */
    public function dependsOn(): array
    {
        return match ($this) {
            self::WordPress => [self::SharedHosting],
            self::Backups => [self::Vps],
            self::Vps, self::Dedicated, self::SharedHosting, self::Domains, self::Dns => [],
        };
    }

    /**
     * Every product, dependencies before dependents, so one pass assesses
     * each product after everything it leans on.
     *
     * @return list<self>
     */
    public static function inDependencyOrder(): array
    {
        $ordered = [];
        $visit = function (self $product) use (&$ordered, &$visit): void {
            if (in_array($product, $ordered, strict: true)) {
                return;
            }

            foreach ($product->dependsOn() as $dependency) {
                $visit($dependency);
            }

            $ordered[] = $product;
        };

        foreach (self::cases() as $product) {
            $visit($product);
        }

        return $ordered;
    }
}
