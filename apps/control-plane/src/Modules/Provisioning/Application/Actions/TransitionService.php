<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ServiceStatusChanged;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ServiceStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;

/**
 * The only place a service's status changes.
 *
 * Centralising it means every change is checked against the state machine and
 * every lifecycle timestamp stays in step with the column it describes, which
 * is impossible to maintain once jobs, controllers and listeners each assign
 * the status themselves.
 *
 * A re-entrant transition is a no-op rather than an error, because retried
 * jobs and redelivered webhooks land here routinely and converging is the
 * point.
 *
 * ---------------------------------------------------------------------------
 * Every real move is announced
 * ---------------------------------------------------------------------------
 *
 * A move that changed the row raises ServiceStatusChanged once the outermost
 * transaction has committed; a converging call raises nothing. Being the only
 * writer is what makes that announcement complete, and the order a service
 * was bought on depends on it: before F-19 the order was paid for and then
 * never told that what it bought was built, suspended or ended.
 */
final readonly class TransitionService
{
    public function __construct(
        private ServiceStateMachine $stateMachine,
    ) {}

    /**
     * @throws IllegalStateTransitionException
     */
    public function execute(Service $service, ServiceStatus $to): Service
    {
        $moved = null;

        /*
         * Deliberately no converge check and no validation before the lock.
         *
         * Both would be decided on the caller's copy, which is exactly the
         * thing this action refuses to trust everywhere else. A model handed
         * to a listener is read once and then passed through several
         * transitions — a reactivation moves a service to `reactivating` and,
         * if the provider will not confirm, back to `suspended` — and by the
         * second call the caller's copy still says what it said when it was
         * loaded. Short-circuiting on it turned that second transition into a
         * silent no-op, leaving a service that could not be reactivated
         * sitting in `reactivating` forever.
         *
         * The cost is a transaction for a call that changes nothing. That is
         * the right price for a status that is always read from the row it is
         * about to change.
         */
        $transitioned = DB::transaction(function () use ($service, $to, &$moved): Service {
            /*
             * Re-read under a row lock and re-check. Two workers can pass the
             * check above concurrently — a suspension for non-payment while a
             * build completes — and without the lock both would write, leaving
             * a service that is suspended and active at the same time
             * depending on which write landed last.
             */
            /** @var Service $locked */
            $locked = Service::query()->lockForUpdate()->findOrFail($service->getKey());

            if ($locked->status === $to) {
                return $locked;
            }

            // The authoritative "from" is the locked row's status, not the
            // caller's possibly stale copy.
            $from = $locked->status;
            $this->stateMachine->assertCanTransition($from, $to);

            $locked->status = $to;
            $this->stampTimestamps($locked, $to);
            $locked->save();

            $moved = new ServiceStatusChanged(
                serviceId: (string) $locked->getKey(),
                orderId: $locked->order_id === null ? null : (string) $locked->order_id,
                from: $from,
                to: $to,
            );

            return $locked;
        });

        if ($moved instanceof ServiceStatusChanged) {
            $this->announce($moved);
        }

        return $transitioned;
    }

    /**
     * Held until the outermost transaction commits. A caller that moves the
     * service inside its own transaction — a decommission, an adoption, an
     * operator's retry — may still roll the move back, and a listener must not
     * act on a status that never became true. With nothing open, the move has
     * already committed and the announcement belongs now.
     */
    private function announce(ServiceStatusChanged $moved): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(static function () use ($moved): void {
                event($moved);
            });

            return;
        }

        event($moved);
    }

    /**
     * Keeps the denormalised lifecycle timestamps in step with the status so
     * that reporting does not have to reconstruct them from job history.
     */
    private function stampTimestamps(Service $service, ServiceStatus $to): void
    {
        match ($to) {
            // Set once: the date a service first went live is a fact about the
            // customer's relationship, not about the last time it was
            // unsuspended.
            ServiceStatus::Active => $service->activated_at ??= now(),
            ServiceStatus::Suspended => $service->suspended_at = now(),
            ServiceStatus::Terminated => $service->terminated_at ??= now(),
            default => null,
        };
    }
}
