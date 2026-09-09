<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\Exceptions;

use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A new sale refused because the product is not ready to sell.
 *
 * Raised on the customer's side of the platform, so it renders like every
 * other refusal there — a code a client can branch on, a sentence a person
 * can read, and no detail about which provider is missing what. Where the
 * product stands is the operator's business; the customer needs to know
 * only that this line is not on sale right now.
 */
final class ProductNotSellable extends DomainException
{
    public static function because(Product $product, ProductReadinessState $state): self
    {
        $exception = new self('This product is not available for new orders at the moment.');

        return $exception->withContext(['product' => $product->value, 'readiness' => $state->value]);
    }

    public function errorCode(): string
    {
        return 'product.not_sellable';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
