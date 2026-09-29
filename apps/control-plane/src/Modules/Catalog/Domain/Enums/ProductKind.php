<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Enums;

/**
 * What the catalogue can put a price on: the kind of every catalogue product,
 * and so of every plan a customer can order.
 *
 * Not the list of what Lynomia sells or is preparing to sell. That is the
 * readiness engine's `Product` enum, in `ProductReadiness\Domain\Enums`,
 * whose docblock says why the two lists differ; read it there rather than
 * here. The relation runs one way: every kind names the readiness product of
 * the same value, and most readiness products have no kind.
 *
 * The join is made by value with `Product::from()`, in the sellability check
 * the catalogue listing reads and in the checkout's pricing guard. A kind
 * with no readiness product of the same value would throw there, on a
 * customer's request; `EveryCatalogueKindIsAReadinessProductTest` in
 * `tests/Architecture` refuses it at build time instead.
 *
 * Adding a case puts nothing on sale: whether a kind may be sold is decided
 * by the software state and the readiness of the product it names, never by
 * the kind existing or a plan having a price. It does open the admin
 * catalogue API to that kind at once, because the request that records a
 * product validates `kind` against this enum. The three kinds, and that each
 * one's readiness product is complete, are pinned by
 * `ConfiguringACatalogueDoesNotMakeAnythingSellableTest` in
 * `tests/Feature/Catalog`, which is where a kind added for anything less than
 * a complete product is refused.
 */
enum ProductKind: string
{
    case Vps = 'vps';
    case Dedicated = 'dedicated';
    case SharedHosting = 'shared_hosting';

    /**
     * Whether a plan of this kind can be provisioned automatically on payment,
     * or whether it needs an operator to allocate physical hardware first.
     */
    public function isAutomaticallyProvisioned(): bool
    {
        return match ($this) {
            self::Vps, self::SharedHosting => true,
            // A dedicated server needs a machine reserved from inventory; if
            // none is free the order waits rather than failing.
            self::Dedicated => false,
        };
    }
}
