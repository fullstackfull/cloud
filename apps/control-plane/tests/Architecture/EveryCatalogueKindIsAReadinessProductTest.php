<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every catalogue kind is a readiness product. Not every readiness product is
 * a catalogue kind.
 *
 * ---------------------------------------------------------------------------
 * The join this pins
 * ---------------------------------------------------------------------------
 *
 * {@see ProductKind} lists what has a price; {@see Product} lists what has to
 * work. The two are joined by value, and in two live places the join is made
 * with `Product::from()`, which throws on a value it does not know:
 *
 *  - `ProductSellability::sellableCatalogueKinds()`, which the production
 *    catalogue listing reads; and
 *  - `OrderPricing::assertEveryLineIsOnSale()`, which every checkout reads.
 *
 * A kind added with no readiness product of the same value does not sell
 * anything. It is an uncaught `ValueError` out of the catalogue listing and
 * the checkout: nothing in `src/` catches one. The `ProductReadiness` model
 * casts its `product` column to the enum as well, so writing a readiness row
 * for such a value throws from the model layer too.
 *
 * What stops a kind added for a `prepared` product from being sold is not
 * this gate and never was the throw. `ProductSellability::maySell()` reads
 * the software state before anything else, in every environment, and refuses
 * a product that is not `complete` in production whatever its readiness row
 * says. Measured: a `Cdn` kind with a `ready_to_sell` row, named as
 * rehearsed, in production, is absent from the sellable kinds and nothing is
 * thrown.
 *
 * ---------------------------------------------------------------------------
 * Why the runtime throw stays behind this gate
 * ---------------------------------------------------------------------------
 *
 * Failing loudly is the safe direction, but a 500 on a live page is the wrong
 * place to learn it: the person who finds out is a customer, and the person
 * who erred saw a green suite. Changing the two call sites to `tryFrom()` and
 * skipping would be worse, not better: a kind that has a price would be
 * silently absent from the catalogue, with no signal anywhere. So the refusal
 * happens here, at build time, and `from()` stays as the last loud backstop,
 * unreachable from a build this gate passed.
 *
 * ---------------------------------------------------------------------------
 * The other gate, which holds more
 * ---------------------------------------------------------------------------
 *
 * This is not the only test that would notice a new kind, and it is the
 * weaker of the two. `ConfiguringACatalogueDoesNotMakeAnythingSellableTest`
 * in `tests/Feature/Catalog`, at
 * `the_deliverable_kinds_are_exactly_the_three_a_build_path_exists_for`,
 * pins the kinds to three literal values and asserts that each one's
 * readiness product is `complete`. No mutation turns the first test below
 * red while leaving that one green. What this file adds is diagnostic:
 *
 *  - it states the join as an invariant, so a kind legitimately added with
 *    its readiness product needs no edit here, where a three-value snapshot
 *    does;
 *  - on drift from the `Product` side (a value renamed there) it fails
 *    cleanly and names both call sites, where the other gate errors with a
 *    raw `ValueError` thrown by `from()` inside itself;
 *  - it needs no database and no framework boot.
 *
 * It does not assert that a catalogue kind's readiness product is
 * `complete`. That requirement is already stated and enforced, by the other
 * gate, with a failure message written for exactly that event; a second copy
 * here would be a second place to keep in step.
 *
 * The second test below is the part nothing else holds: that the join runs
 * one way. The likeliest wrong repair of the first test is to make it
 * bidirectional, and `Product` lists lines on purpose that have no price of
 * their own.
 *
 * ---------------------------------------------------------------------------
 * What this gate does not hold
 * ---------------------------------------------------------------------------
 *
 * It does not forbid a new unguarded `Product::from()` call site on a kind's
 * value. Finding call sites means reading source, which this suite does
 * routinely and which the language cannot do for it; the reason this file
 * does not do it is that it is a different invariant, which deserves its own
 * mechanism rather than a clause here. Nor does anything enforce
 * `ProductKind`'s docblock.
 */
final class EveryCatalogueKindIsAReadinessProductTest extends TestCase
{
    #[Test]
    public function every_catalogue_kind_names_a_readiness_product_of_the_same_value(): void
    {
        $kinds = ProductKind::cases();

        $this->assertNotSame([], $kinds, 'ProductKind has no cases, so this gate checked nothing.');

        $unmatched = array_values(array_map(
            static fn (ProductKind $kind): string => $kind->value,
            array_filter($kinds, static fn (ProductKind $kind): bool => Product::tryFrom($kind->value) === null),
        ));

        $this->assertSame(
            [],
            $unmatched,
            'Catalogue kind(s) with no readiness product of the same value: '.implode(', ', $unmatched).'. '
            .'ProductSellability::sellableCatalogueKinds() and OrderPricing::assertEveryLineIsOnSale() both join a kind '
            .'to its readiness product with Product::from(), so each of these is an uncaught ValueError on the '
            .'production catalogue listing and at checkout. Give the readiness Product enum a case with the same '
            .'value, and decide its software state there, before the kind can exist.',
        );
    }

    #[Test]
    public function the_join_runs_one_way_and_not_every_readiness_product_has_a_price(): void
    {
        $withoutAKind = array_values(array_map(
            static fn (Product $product): string => $product->value,
            array_filter(Product::cases(), static fn (Product $product): bool => ProductKind::tryFrom($product->value) === null),
        ));

        $this->assertNotSame(
            [],
            $withoutAKind,
            'Every readiness product is now a catalogue kind. The two enums are different on purpose: Product also '
            .'lists lines sold through another product\'s price, lines nobody has priced yet, and lines the platform '
            .'is only preparing for (see its docblock). If the kind-to-product gate was made bidirectional, that is '
            .'the wrong repair.',
        );
    }
}
