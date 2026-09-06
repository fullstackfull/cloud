<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Domain\Exceptions;

use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised when a subscription is started from an order line that cannot carry
 * one.
 *
 * A subscription is a promise to keep billing, so it may only be created from
 * money that has actually been captured and from a line that names a plan to
 * keep billing for.
 */
final class SubscriptionCannotStartException extends DomainException
{
    public static function becauseOrderIsUnpaid(string $orderId, OrderStatus $status): self
    {
        $exception = new self(sprintf(
            'Order %s is %s; a subscription may only start from a paid order.',
            $orderId,
            $status->value,
        ));

        return $exception->withContext(['order_id' => $orderId, 'status' => $status->value]);
    }

    public static function becauseLineHasNoPlan(string $orderItemId): self
    {
        $exception = new self(sprintf(
            'Order line %s has no plan, so there is nothing to renew.',
            $orderItemId,
        ));

        return $exception->withContext(['order_item_id' => $orderItemId]);
    }

    public static function becauseLineBelongsToAnotherOrder(string $orderItemId, string $orderId): self
    {
        $exception = new self(sprintf(
            'Order line %s does not belong to order %s.',
            $orderItemId,
            $orderId,
        ));

        return $exception->withContext(['order_item_id' => $orderItemId, 'order_id' => $orderId]);
    }

    public function errorCode(): string
    {
        return 'subscription.cannot_start';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
