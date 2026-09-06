<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Actions;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Domain\StateMachines\OrderStateMachine;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderTransition;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;

/**
 * The only place an order's status changes.
 *
 * Centralising it buys three properties that are impossible to maintain if
 * controllers and jobs assign the column themselves:
 *
 *  - every change is validated against the state machine;
 *  - every change is recorded with its actor, reason and correlation id;
 *  - a re-entrant transition is a no-op rather than a duplicate audit row, so
 *    a redelivered webhook or a retried job converges instead of throwing.
 */
final readonly class TransitionOrder
{
    public function __construct(
        private OrderStateMachine $stateMachine,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws IllegalStateTransitionException
     */
    public function execute(
        Order $order,
        OrderStatus $to,
        string $actorType = 'system',
        ?User $actor = null,
        ?string $reason = null,
        array $context = [],
    ): Order {
        $from = $order->status;

        // Retries and redelivered webhooks land here; converging silently is
        // the point, but it must not manufacture an audit entry that suggests
        // something happened.
        if ($from === $to) {
            return $order;
        }

        $this->stateMachine->assertCanTransition($from, $to);

        return DB::transaction(function () use ($order, $to, $actorType, $actor, $reason, $context): Order {
            /*
             * Re-read under a row lock and re-check.
             *
             * Two workers can pass the state-machine check concurrently — a
             * webhook confirming payment while a timeout job cancels the same
             * order — and without the lock both would write, leaving the audit
             * trail describing a sequence that never happened.
             */
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->status === $to) {
                return $locked;
            }

            // The authoritative "from" is the locked row's status, not the
            // caller's possibly stale copy.
            $originalStatus = $locked->status;
            $this->stateMachine->assertCanTransition($originalStatus, $to);

            $locked->status = $to;
            $this->stampTimestamps($locked, $to);
            $locked->save();

            OrderTransition::create([
                'order_id' => $locked->id,
                'from_status' => $originalStatus,
                'to_status' => $to,
                'actor_type' => $actorType,
                'actor_user_id' => $actor?->id,
                'reason' => $reason,
                'context' => $context === [] ? null : $context,
                'correlation_id' => Context::get('request_id'),
                'created_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * Keeps the denormalised lifecycle timestamps in step with the status, so
     * reporting queries do not have to join the transition table.
     */
    private function stampTimestamps(Order $order, OrderStatus $to): void
    {
        match ($to) {
            OrderStatus::PendingPayment => $order->placed_at ??= now(),
            OrderStatus::Paid => $order->paid_at ??= now(),
            OrderStatus::Active => $order->completed_at ??= now(),
            OrderStatus::Cancelled => $order->cancelled_at ??= now(),
            default => null,
        };
    }
}
