<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Application\Services;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductSoftwareState;
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
 * Outside production the answer is yes for every product in the approved
 * launch scope. Those environments exist to rehearse the sale against
 * controlled providers that can never reach ready_to_sell, and a catalogue
 * that hid everything there would hide the checkout from every test and every
 * staging walk-through. That is the same environment check the guard has
 * always made, kept in one place.
 *
 * A product outside that scope — software written, no production-capable
 * adapter for what it requires — is refused in every environment, including
 * this one, and the readiness row is never reached for it. The exception is
 * named rather than implied: {@see PreparedProductRehearsal} may re-admit a
 * `prepared` product to a rehearsal outside production, and can do nothing
 * whatsoever in it.
 */
final readonly class ProductSellability
{
    public function __construct(
        private Application $app,
        private PreparedProductRehearsal $rehearsal,
    ) {}

    /**
     * Whether a new sale of this product would be permitted now.
     */
    public function maySell(Product $product): bool
    {
        /*
         * Software state first, and in every environment.
         *
         * The readiness row below is an operational judgement about providers
         * that exist. This is a different question and a prior one: does this
         * platform contain a production-capable implementation of what the
         * product requires at all? For a `prepared` product the answer is no
         * by construction, and no amount of provider configuration can change
         * it — so the sale is refused in production, in staging and in a
         * rehearsal alike.
         *
         * Deliberately before `enforced()`. The environment exemption exists
         * so a controlled provider can rehearse a sale that production would
         * refuse for want of a credential; it was never meant to let a
         * product with no real adapter be sold anywhere. And deliberately
         * ahead of the readiness row, so an Admin declaration cannot reach
         * past it: `ready_to_sell` is a person's statement about providers,
         * not a licence to sell software that does not exist.
         *
         * The single exception is a rehearsal, and it is not an exception in
         * production: {@see PreparedProductRehearsal} answers false there
         * before anything else is read. Outside production it lets the
         * controlled simulation walk a prepared lifecycle end to end, which
         * is how that software stays covered while it waits for a provider
         * contract it does not have.
         */
        if ($product->softwareState() !== ProductSoftwareState::Complete) {
            return $this->rehearsal->includes($product);
        }

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
