<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Domain\StateMachines\OrderStateMachine;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Provisioning\Application\Queries\WhatAnOrderBrought;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;

/**
 * An order is what somebody bought, so past `paid` it says what became of that.
 *
 * ---------------------------------------------------------------------------
 * The defect (F-19)
 * ---------------------------------------------------------------------------
 *
 * Nine of the order's thirteen states had no writer. The order was placed,
 * paid for, and never told anything again, because everything after payment
 * happened on the service row. So a purchase delivered in a minute and one no
 * node could ever build both read `paid` for ever; `completed_at`, which the
 * customer API publishes, was always null; and the stock and coupon rules that
 * give a unit back on `refunded` or `terminated` named states no order could
 * enter — a plan with a stock limit of one reported its unit claimed for ever
 * once the machine had been destroyed.
 *
 * ---------------------------------------------------------------------------
 * How the order is told
 * ---------------------------------------------------------------------------
 *
 * Whenever something it bought moves — a service changes status, a build is
 * claimed by a worker or stops — this reads every service the order brought
 * into being, decides where the order stands, and walks the state machine
 * there. It reads the facts rather than trusting the event that woke it, so a
 * late, repeated or out-of-order wake-up converges on the same answer.
 *
 * Where the order stands, for one service:
 *
 *  - build asked for, no worker has claimed it → `queued_for_provisioning`;
 *  - a worker has claimed it → `provisioning`;
 *  - built → `active` (TransitionOrder stamps `completed_at`, once);
 *  - suspended, or paid again and not yet back → `suspended`;
 *  - its build stopped for a person → `manual_review`, and so is a service
 *    waiting for an operator to say where it goes;
 *  - its build was refused → `provisioning_failed`;
 *  - ended → `terminated`.
 *
 * For several, the one needing a person wins, then the one refused, then the
 * build in progress, then the build not yet started; the order is `active`
 * when every live service has been delivered and at least one is serving, and
 * `terminated` only when every one has ended. A plan line that has no service
 * yet counts as a build not yet started, so an order is never `active` while
 * one of its lines has not even been asked for.
 *
 * ---------------------------------------------------------------------------
 * How it gets there
 * ---------------------------------------------------------------------------
 *
 * By the table, and never around it. A move the table allows is made. Along
 * the build — `paid → queued_for_provisioning → provisioning → active` — a
 * wake-up that arrives after a later step (a build that finished inside the
 * dispatch on a synchronous queue) walks the intermediate states rather than
 * jumping, because the purchase genuinely passed through them; and a failed or
 * reviewed build that is retried re-enters that road at
 * `queued_for_provisioning`. Anything else the table does not allow is left
 * alone: the order stays where it is, which is what a refunded order does when
 * its service is later suspended. Nothing here forces a move.
 */
final readonly class KeepTheOrderInStepWithItsServices
{
    /** The road a delivered purchase travels, in order. */
    private const array THE_BUILD = [
        OrderStatus::Paid,
        OrderStatus::QueuedForProvisioning,
        OrderStatus::Provisioning,
        OrderStatus::Active,
    ];

    public function __construct(
        private TransitionOrder $transition,
        private OrderStateMachine $states,
        private WhatAnOrderBrought $brought,
    ) {}

    /**
     * @return Order|null the order as it now stands, or null if there is no such order
     *
     * @throws IllegalStateTransitionException only if the table itself changes underfoot
     */
    public function execute(string $orderId, string $reason): ?Order
    {
        return DB::transaction(function () use ($orderId, $reason): ?Order {
            /*
             * The order row first, and held: two services of one order moving
             * at once must not both read the order's old status and both walk
             * from it.
             */
            /** @var Order|null $order */
            $order = Order::query()->lockForUpdate()->find($orderId);

            if ($order === null) {
                return null;
            }

            $target = $this->whereItStands($order);

            if ($target === null) {
                return $order;
            }

            foreach ($this->route($order->status, $target) as $step) {
                $order = $this->transition->execute($order, $step, actorType: 'system', reason: $reason);
            }

            return $order;
        });
    }

    private function whereItStands(Order $order): ?OrderStatus
    {
        $services = $this->brought->servicesOf((string) $order->getKey());

        $stands = array_map(fn (array $service): ?OrderStatus => $this->standingOf($service), $services);

        // A plan line with no service yet is a build nobody has asked for.
        $lines = $order->items()->whereNotNull('plan_id')->count();

        for ($missing = $lines - count($services); $missing > 0; $missing--) {
            $stands[] = OrderStatus::QueuedForProvisioning;
        }

        if ($stands === []) {
            return null;
        }

        $live = array_values(array_filter($stands, static fn (?OrderStatus $status): bool => $status !== null));

        if ($live === []) {
            return OrderStatus::Terminated;
        }

        foreach ([OrderStatus::ManualReview, OrderStatus::ProvisioningFailed, OrderStatus::Provisioning, OrderStatus::QueuedForProvisioning, OrderStatus::Active] as $first) {
            if (in_array($first, $live, true)) {
                return $first;
            }
        }

        return OrderStatus::Suspended;
    }

    /**
     * Where one service puts the order, or null for a service that has ended.
     *
     * @param  array{status: ServiceStatus, build: ProvisioningJobStatus|null, started: bool}  $service
     */
    private function standingOf(array $service): ?OrderStatus
    {
        return match ($service['status']) {
            ServiceStatus::Terminated => null,
            ServiceStatus::Active => OrderStatus::Active,
            ServiceStatus::Suspended, ServiceStatus::Reactivating => OrderStatus::Suspended,
            ServiceStatus::Failed => $service['build'] === ProvisioningJobStatus::NeedsReview
                ? OrderStatus::ManualReview
                : OrderStatus::ProvisioningFailed,
            ServiceStatus::Pending, ServiceStatus::Provisioning => match (true) {
                // A service nobody has asked to build is waiting for a person
                // to decide where it goes (a placement the platform could not
                // make on its own).
                $service['build'] === null => OrderStatus::ManualReview,
                $service['build'] === ProvisioningJobStatus::NeedsReview => OrderStatus::ManualReview,
                $service['build'] === ProvisioningJobStatus::Failed => OrderStatus::ProvisioningFailed,
                $service['started'] => OrderStatus::Provisioning,
                default => OrderStatus::QueuedForProvisioning,
            },
        };
    }

    /**
     * The moves that take the order from where it is to where it stands, each
     * one a move the table allows; empty when there is no such road.
     *
     * @return list<OrderStatus>
     */
    private function route(OrderStatus $from, OrderStatus $to): array
    {
        if ($from === $to) {
            return [];
        }

        if ($this->states->canTransition($from, $to)) {
            return [$to];
        }

        $goal = array_search($to, self::THE_BUILD, true);

        if ($goal === false) {
            return [];
        }

        $at = array_search($from, self::THE_BUILD, true);

        if ($at !== false) {
            return $goal > $at ? array_slice(self::THE_BUILD, $at + 1, $goal - $at) : [];
        }

        // A failed or reviewed build that is being built again re-enters the
        // road where a retry puts it.
        if (in_array($from, [OrderStatus::ProvisioningFailed, OrderStatus::ManualReview], true) && $goal >= 1) {
            return array_slice(self::THE_BUILD, 1, $goal);
        }

        return [];
    }
}
