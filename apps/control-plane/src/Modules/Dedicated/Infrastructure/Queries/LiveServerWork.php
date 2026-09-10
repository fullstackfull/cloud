<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Queries;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Dedicated\Domain\Services\DedicatedOperationGuard;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * The live (queued or running) job, if any, against each of a set of
 * physical machines — the fact {@see DedicatedOperationGuard}
 * refuses on, asked once for a page of servers.
 *
 * The two predicates are the guard's own: a job names a machine in its
 * payload, or it names the service the machine fulfils. A machine with no
 * service is matched by the payload alone rather than by `service_id = null`,
 * which would match every serviceless job in the platform.
 *
 * The answer distinguishes a reinstall from other work because the guard
 * does: a power request is refused only while a REINSTALL is live (it is the
 * customer's recovery tool and must not be taken away by an unrelated sweep),
 * whereas a reinstall request is refused while ANY job is live.
 */
final class LiveServerWork
{
    /**
     * @param  list<DedicatedServer>  $servers
     * @return array<string, ProvisioningJobKind> server id → kind of the live job, absent when none
     */
    public static function forServers(array $servers): array
    {
        if ($servers === []) {
            return [];
        }

        $serverIds = array_map(static fn (DedicatedServer $server): string => (string) $server->getKey(), $servers);
        $serviceIds = array_values(array_filter(array_map(
            static fn (DedicatedServer $server): ?string => $server->service_id,
            $servers,
        )));

        $jobs = ProvisioningJob::query()
            ->whereIn('status', [
                ProvisioningJobStatus::Queued->value,
                ProvisioningJobStatus::Running->value,
            ])
            ->where(static function (Builder $query) use ($serverIds, $serviceIds): void {
                $query->whereIn('payload->dedicated_server_id', $serverIds);

                if ($serviceIds !== []) {
                    $query->orWhereIn('service_id', $serviceIds);
                }
            })
            ->get(['kind', 'service_id', 'payload']);

        $byService = [];
        foreach ($servers as $server) {
            if ($server->service_id !== null) {
                $byService[$server->service_id] = (string) $server->getKey();
            }
        }

        $live = [];

        foreach ($jobs as $job) {
            /** @var array<string, mixed> $payload */
            $payload = $job->payload ?? [];
            $target = $payload['dedicated_server_id'] ?? null;

            if (! is_string($target) && $job->service_id !== null) {
                $target = $byService[$job->service_id] ?? null;
            }

            if (! is_string($target)) {
                continue;
            }

            // A live reinstall is the fact that blocks the most, so it wins
            // over any other live job on the same machine.
            if (($live[$target] ?? null) !== ProvisioningJobKind::ReinstallDedicated) {
                $live[$target] = $job->kind;
            }
        }

        return $live;
    }
}
