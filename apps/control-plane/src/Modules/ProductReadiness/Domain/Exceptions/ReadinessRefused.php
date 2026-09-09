<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\Exceptions;

use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use RuntimeException;

final class ReadinessRefused extends RuntimeException
{
    public static function notReadyForProduction(Product $product, ProductReadinessState $state, ?string $detail): self
    {
        return new self(sprintf(
            '%s cannot be declared sellable: it is %s, not ready for production. %s '
            .'A declaration records that a person validated the product against real, enabled providers; '
            .'it cannot stand in for them.',
            $product->value,
            $state->value,
            $detail ?? '',
        ));
    }

    /**
     * A product whose software is prepared or readiness-only cannot be
     * declared, whatever its providers report. There is nothing a person
     * could have validated.
     */
    public static function softwareNotComplete(Product $product): self
    {
        return new self(sprintf(
            '%s cannot be declared sellable: its software is %s. '
            .'A prepared product has a provider contract and a readiness row and no customer flow; '
            .'nothing exists for a person to have validated.',
            $product->value,
            $product->softwareState()->value,
        ));
    }

    public static function notDeclared(Product $product): self
    {
        return new self(sprintf('%s has no sellability declaration to withdraw.', $product->value));
    }
}
