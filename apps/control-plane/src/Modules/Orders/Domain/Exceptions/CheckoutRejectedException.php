<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A checkout the platform will not accept.
 *
 * Each reason carries its own machine-readable code so the storefront can react
 * specifically — a plan that has just sold out needs a different response from a
 * suspended account, and both need a different response from an empty basket.
 */
final class CheckoutRejectedException extends DomainException
{
    /*
     * Deliberately NOT named $code: Exception already declares an untyped
     * $code, and redeclaring it with a type is a fatal error at class load —
     * which surfaces as a killed PHP process rather than a readable message.
     */
    private string $errorCode = 'checkout.rejected';

    /*
     * Almost every rejection here is 422 - the request was understood and the
     * basket was not acceptable. A reused idempotency key is different: the
     * request is perfectly valid and conflicts with one already recorded, which
     * is what 409 means and what a client library needs to see to stop retrying
     * the same key.
     */
    private int $httpStatus = 422;

    public static function becauseBasketIsEmpty(): self
    {
        return (new self('An order must contain at least one item.'))->as('checkout.empty_basket');
    }

    public static function becauseAccountCannotPurchase(string $status): self
    {
        $exception = new self('This account cannot place new orders.');
        $exception->withContext(['account_status' => $status]);

        return $exception->as('checkout.account_not_purchasable');
    }

    public static function becausePlanIsUnavailable(string $planId): self
    {
        $exception = new self('One of the selected plans is no longer available.');
        $exception->withContext(['plan_id' => $planId]);

        return $exception->as('checkout.plan_unavailable');
    }

    /**
     * The platform sells a plan in the currencies and periods someone actually
     * priced it in. It never converts, because a converted price is a price
     * nobody set.
     */
    public static function becausePlanIsNotSoldOnTheseTerms(string $planId, string $currency, string $period): self
    {
        $exception = new self('That plan is not sold in your currency on that billing period.');
        $exception->withContext(['plan_id' => $planId, 'currency' => $currency, 'billing_period' => $period]);

        return $exception->as('checkout.terms_unavailable');
    }

    public static function becausePlanIsOutOfStock(string $planId): self
    {
        $exception = new self('That plan has just sold out.');
        $exception->withContext(['plan_id' => $planId]);

        return $exception->as('checkout.out_of_stock');
    }

    public static function becausePerCustomerLimitReached(string $planId, int $limit): self
    {
        $exception = new self('You have reached the limit for that plan.');
        $exception->withContext(['plan_id' => $planId, 'limit' => $limit]);

        return $exception->as('checkout.per_customer_limit');
    }

    public static function becauseQuantityIsInvalid(string $planId, int $quantity): self
    {
        $exception = new self('The requested quantity is not valid.');
        $exception->withContext(['plan_id' => $planId, 'quantity' => $quantity]);

        return $exception->as('checkout.invalid_quantity');
    }

    public static function becauseIdempotencyKeyWasReused(string $key): self
    {
        $exception = new self(
            'This idempotency key was already used for a different order. Retry with a new key.'
        );
        $exception->errorCode = 'checkout.idempotency_key_reused';
        $exception->httpStatus = 409;

        /*
         * The key is echoed because the client sent it and already knows it.
         * Nothing about the original order is: what somebody else - or the same
         * customer in another tab - bought under that key is not this request's
         * business.
         */
        return $exception->withContext(['idempotency_key' => $key]);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }
}
