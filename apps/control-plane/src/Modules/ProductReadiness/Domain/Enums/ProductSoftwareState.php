<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\Enums;

/**
 * How much of a product's software exists, declared in source per product.
 *
 * The readiness ladder measures providers. It cannot measure whether the
 * customer flow that would use those providers has been written, and a
 * product with a perfect provider and no checkout is not a product. So each
 * product declares which of three things it is, and the engine caps the
 * ladder accordingly:
 *
 *   complete        an order path, a provisioning path, a customer surface
 *                   and a whole-life test exist. The ladder applies in full.
 *   prepared        the provider contract, the capability set and the
 *                   readiness row exist; no customer can buy it. Capped
 *                   below production, because there is nothing to enable.
 *   readiness_only  only the readiness row exists, by decree: the platform
 *                   is to know what the product would need and nothing more.
 *                   Capped the same way, and a declaration is refused
 *                   outright rather than merely failing the rung check.
 *
 * Reviewed like code, because it is code: the unit test that walks every
 * product asserts that a `complete` product has an order action and that a
 * `readiness_only` one has no customer route.
 */
enum ProductSoftwareState: string
{
    case Complete = 'complete';
    case Prepared = 'prepared';
    case ReadinessOnly = 'readiness_only';

    /**
     * The highest rung a product in this software state may reach.
     *
     * Production means "nothing in the control plane stops live use", and
     * software that does not exist is precisely such a stop. Real validation
     * is still reachable: a real provider can answer and be discovered
     * against before the product that will call it is written, and saying
     * so is how the operator knows the provider side is done.
     */
    public function ceiling(): ProductReadinessState
    {
        return match ($this) {
            self::Complete => ProductReadinessState::ReadyForProduction,
            self::Prepared, self::ReadinessOnly => ProductReadinessState::ReadyForRealValidation,
        };
    }

    public function maySell(): bool
    {
        return $this === self::Complete;
    }
}
