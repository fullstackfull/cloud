<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Actions;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Provisioning\Application\Services\LocalPlacementFeasibility;

/**
 * Asks again, at the last moment before money moves, whether this order can
 * still be delivered.
 *
 * ---------------------------------------------------------------------------
 * Why once is not enough
 * ---------------------------------------------------------------------------
 *
 * Checkout refuses a plan the platform cannot place, which stops the order
 * being accepted at all. But an order is accepted at 10:00, an operator
 * removes the hosting package at 10:05, and the customer presses Pay at 10:10.
 * The invoice is still open and still collectible, and nothing between the two
 * moments would have noticed.
 *
 * So the question is asked again where the money actually starts moving. It is
 * the same question, through the same authority — a second implementation here
 * would be the drift this whole arrangement exists to avoid.
 *
 * ---------------------------------------------------------------------------
 * What this does not promise
 * ---------------------------------------------------------------------------
 *
 * Not that configuration can never disappear after this returns. The check and
 * the capture are not one atomic act and cannot be: the capture happens at a
 * provider, minutes later, through a webhook. What the platform can guarantee
 * is that it will not *start* taking money for something it already knows it
 * cannot deliver.
 *
 * The remaining window is covered downstream rather than pretended away: a
 * service whose placement became impossible after payment is kept, marked with
 * its reason, and shown to an operator. Losing a paid customer's row quietly
 * would be the worse failure.
 */
final readonly class AssertOrderIsStillDeliverable
{
    public function __construct(
        private LocalPlacementFeasibility $placement,
    ) {}

    /**
     * @throws CheckoutRejectedException
     */
    public function execute(Order $order): void
    {
        $planIds = $order->items()->whereNotNull('plan_id')->pluck('plan_id')->unique();

        foreach ($planIds as $planId) {
            /** @var Plan|null $plan */
            $plan = Plan::query()->with('product')->find($planId);

            if ($plan === null) {
                /*
                 * The plan itself is gone. Nothing can be resolved from a row
                 * that no longer exists, and charging for it would be charging
                 * for a catalogue entry the platform has forgotten.
                 */
                $this->recordTheRefusal(
                    $order,
                    (string) $planId,
                    'the plan this order was placed against no longer exists',
                );

                throw CheckoutRejectedException::becauseItCannotBeDelivered((string) $planId);
            }

            $resolution = $this->placement->resolve($plan);

            if (! $resolution->isFeasible()) {
                $this->recordTheRefusal($order, (string) $plan->getKey(), (string) $resolution->blockedReason);

                throw CheckoutRejectedException::becauseItCannotBeDelivered((string) $plan->getKey());
            }
        }
    }

    /**
     * Writes down why, for the operator who will be asked about it.
     *
     * The customer is told "not right now", and deliberately no more: the
     * reason names a cluster, an IP pool or a panel package. An operator still
     * has to be able to answer "why was my payment refused?", and this is
     * where that answer lives — with the order and the plan beside it, and
     * with the request id the log already carries.
     */
    private function recordTheRefusal(Order $order, string $planId, string $reason): void
    {
        Log::warning('A payment was refused because the order can no longer be delivered.', [
            'order_id' => (string) $order->getKey(),
            'customer_id' => (string) $order->customer_id,
            'plan_id' => $planId,
            'reason' => $reason,
        ]);
    }
}
