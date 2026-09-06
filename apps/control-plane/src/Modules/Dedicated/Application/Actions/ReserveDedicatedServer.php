<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\NoMatchingHardwareException;
use Lynomia\Modules\Dedicated\Domain\StateMachines\DedicatedServerStateMachine;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;

/**
 * Takes one physical machine out of stock and holds it for one order.
 *
 * This is the only place dedicated_servers.status becomes `reserved`, and the
 * row lock is the whole reason it exists as an action rather than an update at
 * a call site.
 *
 * Two operators approving two orders in the same minute are two requests
 * against the same free machine. Both read the inventory, both see it
 * available, and both are right at the moment they read. Without the lock both
 * write, and the outcome is one machine promised to two customers — which,
 * unlike an oversold VPS node, cannot be resolved by moving anybody: there is
 * one box, and the second customer's server has to be un-sold by a human.
 *
 * The candidate is selected and locked in one statement, so the row a worker
 * decides on is the row it holds. Selecting first and locking afterwards would
 * reintroduce exactly the window this exists to close.
 *
 * The match is EXACT on hardware profile. Substituting "something similar" is
 * not a smaller CPU: on physical hardware it is a different disk layout, a
 * different NIC and a different price. When nothing matches, this throws, and
 * the order goes to MANUAL_REVIEW rather than being refunded — a customer
 * content to wait a day is worth more than a refund.
 */
final readonly class ReserveDedicatedServer
{
    private const int FALLBACK_HOLD_MINUTES = 120;

    public function __construct(
        private DedicatedServerStateMachine $states,
    ) {}

    /**
     * @param  string|null  $orderId  The order the machine is held for. Also the idempotency key:
     *                                a retried job finds the hold it already placed rather than
     *                                taking a second machine out of stock.
     * @param  int|null  $holdMinutes  How long the hold stands before a reaper may release it. Null
     *                                 takes the configured default; an explicit 0 means indefinite,
     *                                 which is what an operator holding a machine for a named
     *                                 customer wants.
     *
     * @throws NoMatchingHardwareException
     */
    public function execute(
        string $hardwareProfile,
        string $datacenterId,
        ?string $orderId = null,
        ?string $customerId = null,
        ?string $serviceId = null,
        ?int $holdMinutes = null,
    ): DedicatedServer {
        $minutes = $holdMinutes ?? (int) config('dedicated.reservation.hold_minutes', self::FALLBACK_HOLD_MINUTES);

        return DB::transaction(function () use (
            $hardwareProfile, $datacenterId, $orderId, $customerId, $serviceId, $minutes
        ): DedicatedServer {
            /*
             * What this order already holds, before anything new is taken.
             *
             * A provisioning job is retried: the engine puts a failed attempt
             * back on the queue and a second worker runs it from the top.
             * Without this lookup the second attempt reserves a SECOND
             * machine, and the first is stranded — held for an order that is
             * now pointing at a different box, with nothing to release it
             * except a person noticing the stock count is wrong.
             */
            if ($orderId !== null) {
                $existing = DedicatedServer::query()
                    ->where('reserved_by_order_id', $orderId)
                    ->whereIn('status', [
                        DedicatedServerStatus::Reserved->value,
                        // A machine already being installed for this order is
                        // also "what this order holds": a retry that reserved
                        // another machine while the first was mid-install
                        // would leave an installer running on a box nobody is
                        // watching.
                        DedicatedServerStatus::Provisioning->value,
                    ])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            /*
             * Select and lock in one statement.
             *
             * A plain lock rather than SKIP LOCKED, deliberately. Skipping
             * would let the second worker take a DIFFERENT machine while the
             * first is still deciding — which is fine when there is plenty of
             * stock and wrong when there is one machine left, because the
             * second worker would report "none available" while the first was
             * still able to roll back. Waiting means the second worker sees
             * the committed truth: either the machine is gone, or it is free
             * again because the first transaction failed.
             */
            $server = DedicatedServer::query()
                ->allocatable()
                ->where('hardware_profile', $hardwareProfile)
                ->where('datacenter_id', $datacenterId)
                // Oldest first, so stock rotates rather than one machine being
                // handed out, released and handed out again while its
                // neighbours never leave the rack.
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($server === null) {
                throw NoMatchingHardwareException::forProfile(
                    $hardwareProfile,
                    $datacenterId,
                    DedicatedServer::query()
                        ->allocatable()
                        ->where('datacenter_id', $datacenterId)
                        ->count(),
                );
            }

            // Validated even though the query filtered on it. The state machine
            // is the module's single statement of what may follow what, and a
            // reservation that bypassed it would be the one write to this
            // column that nothing checks.
            $this->states->assertCanTransition($server->status, DedicatedServerStatus::Reserved);

            $server->forceFill([
                'status' => DedicatedServerStatus::Reserved,
                'reserved_by_order_id' => $orderId,
                'customer_id' => $customerId,
                'service_id' => $serviceId,
                // Zero means "held until somebody releases it", which is what
                // an operator holding a machine for a named customer wants and
                // what a reaper must not overrule.
                'reserved_until' => $minutes > 0 ? now()->addMinutes($minutes) : null,
            ])->save();

            return $server;
        });
    }
}
