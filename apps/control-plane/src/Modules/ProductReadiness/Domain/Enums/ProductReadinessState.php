<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\Enums;

/**
 * How far a product may be trusted, on a ladder the platform climbs one rung
 * at a time and cannot skip.
 *
 * The rungs are about WHO answered, not about how complete the configuration
 * looks:
 *
 *   ready_for_test             every requirement is met by a proven provider,
 *                              and a controlled (fake) one counts. The
 *                              product's whole lifecycle can be rehearsed.
 *   ready_for_real_validation  every requirement is met by a proven provider
 *                              that is NOT controlled. Somebody real has
 *                              answered; nothing real has been sold through it.
 *   ready_for_production       every requirement is met by a real provider
 *                              that is enabled in the production environment.
 *   ready_to_sell              ready_for_production, and a person has recorded
 *                              that the product was validated against those
 *                              providers, with a reference to the validation.
 *
 * The last rung is the one the platform cannot reach on its own, by design.
 * Readiness is what the control plane can determine about itself; that a
 * product actually works is something a real provider did when asked, and no
 * amount of readiness adds up to it. The declaration is withdrawn, audited,
 * the moment any requirement falls below production — so a product is never
 * sellable on the strength of a provider that has since stopped answering.
 */
enum ProductReadinessState: string
{
    case NotReady = 'not_ready';
    case ReadyForTest = 'ready_for_test';
    case ReadyForRealValidation = 'ready_for_real_validation';
    case ReadyForProduction = 'ready_for_production';
    case ReadyToSell = 'ready_to_sell';

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public static function lowest(self $a, self $b): self
    {
        return $a->rank() <= $b->rank() ? $a : $b;
    }

    /**
     * The rung above this one, or null at the top. What a blocker is measured
     * against: "what stops this product from being ready for real validation"
     * is a question about the next rung, never about one already reached.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::NotReady => self::ReadyForTest,
            self::ReadyForTest => self::ReadyForRealValidation,
            self::ReadyForRealValidation => self::ReadyForProduction,
            self::ReadyForProduction => self::ReadyToSell,
            self::ReadyToSell => null,
        };
    }

    private function rank(): int
    {
        return match ($this) {
            self::NotReady => 0,
            self::ReadyForTest => 1,
            self::ReadyForRealValidation => 2,
            self::ReadyForProduction => 3,
            self::ReadyToSell => 4,
        };
    }
}
