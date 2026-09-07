<?php

declare(strict_types=1);

namespace Lynomia\Support\Provisioning;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Provisioning\Domain\Contracts\ResourceReservationReleaser;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Throwable;

/**
 * Runs every releaser the platform has, so a failed build gives back
 * everything it took.
 *
 * A single binding was the reason capacity leaked. The engine asks one
 * releaser to hand back what a job reserved, that binding pointed at the IPAM
 * releaser, and the address came back while the node's committed cpu, memory
 * and storage did not — for every failed build, for ever. Nothing was wrong
 * with either releaser; there was only ever room for one.
 *
 * Lives outside the modules because it is wiring: the engine must not know
 * that IPAM and Compute both exist, and neither module may know about the
 * other. This is the composition, and InfrastructureServiceProvider — already
 * the one file that knows both halves — builds it.
 *
 * ---------------------------------------------------------------------------
 * One failure does not stop the rest
 * ---------------------------------------------------------------------------
 *
 * Each releaser is run inside its own try. Compensation is what runs when
 * something has already gone wrong, and the failure modes it is cleaning up
 * after — a node that vanished, a pool that was deleted — are exactly the ones
 * that can make one releaser throw. Letting that abort the others would mean a
 * database hiccup while returning an address also permanently loses a node's
 * capacity, which is the outcome this class exists to prevent.
 *
 * The throw is recorded and re-raised only if every releaser failed, so the
 * engine still learns that compensation did not happen at all.
 */
final readonly class EveryReservationReleaser implements ResourceReservationReleaser
{
    /**
     * @param  list<ResourceReservationReleaser>  $releasers
     */
    public function __construct(
        private array $releasers,
    ) {}

    public function release(ProvisioningJob $job): int
    {
        return $this->each(
            $job,
            static fn (ResourceReservationReleaser $releaser): int => $releaser->release($job),
            'release',
        );
    }

    public function quarantine(ProvisioningJob $job, string $reason): int
    {
        return $this->each(
            $job,
            static fn (ResourceReservationReleaser $releaser): int => $releaser->quarantine($job, $reason),
            'quarantine',
        );
    }

    /**
     * @param  callable(ResourceReservationReleaser): int  $run
     */
    private function each(ProvisioningJob $job, callable $run, string $operation): int
    {
        $total = 0;
        $failures = 0;
        $last = null;

        foreach ($this->releasers as $releaser) {
            try {
                $total += $run($releaser);
            } catch (Throwable $e) {
                $failures++;
                $last = $e;

                Log::error('A releaser failed while compensating a provisioning job.', [
                    'provisioning_job_id' => (string) $job->getKey(),
                    'releaser' => $releaser::class,
                    'operation' => $operation,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        if ($last !== null && $failures === count($this->releasers)) {
            // Every one of them failed, so nothing was compensated. The engine
            // has to hear about that rather than record a clean compensation.
            throw $last;
        }

        return $total;
    }
}
