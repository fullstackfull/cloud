<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\DTOs;

/**
 * One plan the customer wants, how many, and — for hosting — the name it is for.
 *
 * Deliberately carries no price. The price is never taken from the client: a
 * checkout that accepts a submitted amount is a checkout where the customer
 * sets their own price.
 *
 * The domain is the one thing a customer supplies that the catalogue cannot:
 * a shared-hosting account is built for a name, and nothing else in the
 * platform knows which. It is carried as submitted; PlaceOrder validates it
 * against the platform's host-name rules and folds it before anything is
 * written or fingerprinted.
 *
 * @immutable
 */
final readonly class CheckoutLine
{
    public function __construct(
        public string $planId,
        public int $quantity = 1,
        public ?string $domain = null,
    ) {}
}
