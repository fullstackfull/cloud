<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Application\Services;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Infrastructure\Models\ProductReadiness;

/**
 * The one answer to "may this product be sold right now?".
 *
 * Two callers ask it and they have to agree: the order guard, which refuses a
 * sale it would not permit, and the catalogue, which must not present a
 * product as purchasable when that guard would refuse it. Before this class
 * the guard decided alone and the catalogue never asked, so a product the
 * readiness ladder had withdrawn stayed on the shelf with a "Place order"
 * button that answered 409. The invariant is now structural: both read the
 * same method, so "listed" and "sellable" cannot drift apart.
 *
 * The policy is the readiness engine's, not this class's. In production a
 * product is sellable only while its row says ready_to_sell — a person's
 * standing declaration on top of real, enabled, discovered providers,
 * withdrawn automatically the moment any of that goes. Nothing here infers
 * readiness from a provider being enabled, a plan existing, or software being
 * implemented; a controlled provider caps a product at ready_for_test and
 * this class reads the row it produced, never the provider.
 *
 * Outside production the answer is always yes. Every other environment exists
 * to rehearse the sale against controlled providers that can never reach
 * ready_to_sell, and a catalogue that hid everything there would hide the
 * checkout from every test and every staging walk-through. That is the same
 * environment check the guard has always made, kept in one place.
 */
final readonly class ProductSellability
{
    public function __construct(
        private Application $app,
    ) {}

    /**
     * Whether a new sale of this product would be permitted now.
     */
    public function maySell(Product $product): bool
    {
        if (! $this->enforced()) {
            return true;
        }

        return $this->state($product) === ProductReadinessState::ReadyToSell;
    }

    /**
     * The readiness state the decision was made on, for the refusal message.
     */
    public function state(Product $product): ProductReadinessState
    {
        $row = ProductReadiness::query()->where('product', $product->value)->first();

        return $row->state ?? ProductReadinessState::NotReady;
    }

    /**
     * The catalogue kinds a customer may currently be offered, or null when
     * the environment restricts nothing.
     *
     * Null rather than "every kind" so a caller can tell "no restriction"
     * from "every kind happens to be sellable", and so the catalogue query
     * adds no predicate at all outside production.
     *
     * A catalogue kind names a readiness product by the same value (vps,
     * dedicated, shared_hosting): the catalogue lists what has a price and the
     * ladder lists what has to work, and those are the same three lines. A
     * catalogue kind the ladder does not know cannot be priced into the
     * catalogue at all — `Product::from` refuses it — which is the safe
     * default: nothing is sellable on the strength of having a price.
     *
     * @return list<string>|null
     */
    public function sellableCatalogueKinds(): ?array
    {
        if (! $this->enforced()) {
            return null;
        }

        $kinds = [];

        foreach (ProductKind::cases() as $kind) {
            if ($this->maySell(Product::from($kind->value))) {
                $kinds[] = $kind->value;
            }
        }

        return $kinds;
    }

    /**
     * Whether readiness gates sales in this environment.
     */
    public function enforced(): bool
    {
        return $this->app->environment('production');
    }
}
