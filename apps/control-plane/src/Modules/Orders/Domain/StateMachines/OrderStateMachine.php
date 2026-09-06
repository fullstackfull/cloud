<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Domain\StateMachines;

use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Shared\Domain\Contracts\AbstractStateMachine;

/**
 * The order lifecycle.
 *
 * The table is the whole specification: nothing else in the application decides
 * what an order may do next, and no code writes the status column directly.
 *
 * @extends AbstractStateMachine<OrderStatus>
 */
final class OrderStateMachine extends AbstractStateMachine
{
    public function subject(): string
    {
        return 'Order';
    }

    /**
     * @return array<string, list<OrderStatus>>
     */
    public function transitions(): array
    {
        return [
            OrderStatus::Draft->value => [
                OrderStatus::PendingPayment,
                OrderStatus::Cancelled,
                // A zero-total order — fully covered by wallet credit or a
                // 100% coupon — skips the payment stage entirely.
                OrderStatus::Paid,
            ],

            OrderStatus::PendingPayment->value => [
                OrderStatus::Paid,
                OrderStatus::PaymentFailed,
                OrderStatus::Cancelled,
                // Fraud and risk checks can divert an order before capture.
                OrderStatus::ManualReview,
            ],

            OrderStatus::PaymentFailed->value => [
                // The customer may retry with another method.
                OrderStatus::PendingPayment,
                OrderStatus::Cancelled,
            ],

            OrderStatus::Paid->value => [
                OrderStatus::QueuedForProvisioning,
                OrderStatus::ManualReview,
                OrderStatus::Refunded,
            ],

            OrderStatus::QueuedForProvisioning->value => [
                OrderStatus::Provisioning,
                OrderStatus::ProvisioningFailed,
                OrderStatus::ManualReview,
            ],

            OrderStatus::Provisioning->value => [
                OrderStatus::Active,
                OrderStatus::ProvisioningFailed,
                OrderStatus::ManualReview,
            ],

            OrderStatus::ProvisioningFailed->value => [
                // Retried by an operator or by the bounded retry policy.
                OrderStatus::QueuedForProvisioning,
                OrderStatus::ManualReview,
                OrderStatus::Refunded,
                OrderStatus::Cancelled,
            ],

            OrderStatus::ManualReview->value => [
                OrderStatus::QueuedForProvisioning,
                OrderStatus::Active,
                OrderStatus::Refunded,
                OrderStatus::Cancelled,
            ],

            OrderStatus::Active->value => [
                OrderStatus::Suspended,
                OrderStatus::Terminated,
                OrderStatus::Refunded,
            ],

            OrderStatus::Suspended->value => [
                OrderStatus::Active,
                OrderStatus::Terminated,
            ],

            // Terminal states have no outgoing transitions. An order that has
            // been refunded or terminated is history, not a workflow.
            OrderStatus::Cancelled->value => [],
            OrderStatus::Refunded->value => [],
            OrderStatus::Terminated->value => [],
        ];
    }
}
