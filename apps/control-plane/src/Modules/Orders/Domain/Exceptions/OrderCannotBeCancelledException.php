<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Domain\Exceptions;

use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A cancellation the platform will not perform.
 *
 * Two reasons, kept apart because the customer's next step differs completely.
 * An order whose money has been captured is not cancelled, it is refunded —
 * a different operation, with different authorisation and a different effect on
 * the ledger — and telling the caller "already paid" points them at it. An
 * order that has simply moved past the point of cancellation (it is already
 * cancelled out, refunded or terminated) has nothing left to do.
 */
final class OrderCannotBeCancelledException extends DomainException
{
    /*
     * Not named $code: Exception already declares an untyped $code and
     * redeclaring it with a type is a fatal error at class load.
     */
    private string $errorCode = 'order.not_cancellable';

    public static function becauseItIsPaid(string $orderId, OrderStatus $status): self
    {
        $exception = new self(
            'This order has already been paid. Cancelling it is a refund, which is a separate request.'
        );
        $exception->withContext(['order_id' => $orderId, 'status' => $status->value]);

        return $exception->as('order.already_paid');
    }

    public static function becauseOfItsStatus(string $orderId, OrderStatus $status): self
    {
        $exception = new self('This order can no longer be cancelled.');
        $exception->withContext(['order_id' => $orderId, 'status' => $status->value]);

        return $exception->as('order.not_cancellable');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * 409, not 422: the request is well formed and the caller is entitled to
     * make it. It is the order's current state that refuses, and that state can
     * change — which is exactly what 409 says and 422 does not.
     */
    public function httpStatus(): int
    {
        return 409;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }
}
