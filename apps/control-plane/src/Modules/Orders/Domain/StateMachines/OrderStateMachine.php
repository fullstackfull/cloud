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
 * Past `paid`, an order follows what it bought (F-19). For nine of its
 * thirteen states nothing used to write them: fulfilment happened on the
 * service row and the order was never told, so a delivered purchase and one
 * nobody could build both read `paid` for ever. KeepTheOrderInStepWithItsServices
 * now walks this table as the services move — queued, being built, active,
 * suspended, ended — and the listeners beside it record a failed first
 * payment and a refund. Each of them asks this table, and each converges on
 * a move it does not allow rather than forcing one.
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
                /*
                 * The invoice stays collectible after a declined attempt, and
                 * a later one — or the same intent completing after a 3-D
                 * Secure challenge — settles it without anybody starting a new
                 * payment. Refusing the move would leave money captured
                 * against an order that cannot say it was paid.
                 */
                OrderStatus::Paid,
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
                // The service ended with nothing built for it: an operator
                // closed a build that left nothing behind (F-19).
                OrderStatus::Terminated,
            ],

            OrderStatus::ManualReview->value => [
                OrderStatus::QueuedForProvisioning,
                OrderStatus::Active,
                OrderStatus::Refunded,
                OrderStatus::Cancelled,
                // As above: the review ended in closing the service.
                OrderStatus::Terminated,
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

            /*
             * Terminal states have no outgoing transitions. An order that has
             * been refunded or terminated is history, not a workflow.
             *
             * Refunded stays terminal even though the service it bought may
             * still be running — a refund records the money and nothing else.
             * What that order still holds, a plan unit and a coupon use, is
             * given back when the service ends, and PlanCapacity reads that
             * from the service rather than from a second move here.
             */
            OrderStatus::Cancelled->value => [],
            OrderStatus::Refunded->value => [],
            OrderStatus::Terminated->value => [],
        ];
    }
}
