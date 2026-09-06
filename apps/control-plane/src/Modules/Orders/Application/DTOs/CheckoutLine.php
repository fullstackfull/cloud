<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\DTOs;

/**
 * One plan the customer wants, and how many.
 *
 * Deliberately carries only an id and a quantity. The price is never taken from
 * the client: a checkout that accepts a submitted amount is a checkout where the
 * customer sets their own price.
 *
 * @immutable
 */
final readonly class CheckoutLine
{
    public function __construct(
        public string $planId,
        public int $quantity = 1,
    ) {}
}
