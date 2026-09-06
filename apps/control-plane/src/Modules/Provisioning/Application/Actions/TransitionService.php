<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
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
        if ($service->status === $to) {
            return $service;
        }

        $this->stateMachine->assertCanTransition($service->status, $to);

        return DB::transaction(function () use ($service, $to): Service {
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
            $this->stateMachine->assertCanTransition($locked->status, $to);

            $locked->status = $to;
            $this->stampTimestamps($locked, $to);
            $locked->save();

            return $locked;
        });
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
