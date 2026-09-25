<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Queries;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * The services an order brought into being, and where each one's build stands
 * — the facts the Orders module reads to keep an order in step with what it
 * bought (F-19), asked of this module rather than read out of its tables.
 *
 * A service's status alone cannot say everything the order distinguishes. An
 * ordered service is `provisioning` from the moment its build is asked for, so
 * "asked for" and "being built" differ only on the build job, by whether a
 * worker has claimed it; and a `failed` service is either one whose build
 * stopped for a person to look at (`needs_review`) or one that was refused
 * outright (`failed`), which is again the job's to say. So each service is
 * answered with its status and its latest build job's.
 */
final class WhatAnOrderBrought
{
    /**
     * @return list<array{status: ServiceStatus, build: ProvisioningJobStatus|null, started: bool}>
     */
    public function servicesOf(string $orderId): array
    {
        $services = Service::query()
            ->where('order_id', $orderId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $answer = [];

        foreach ($services as $service) {
            /** @var ProvisioningJob|null $build */
            $build = ProvisioningJob::query()
                ->where('service_id', $service->getKey())
                ->whereIn('kind', self::buildKinds())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $answer[] = [
                'status' => $service->status,
                'build' => $build?->status,
                // Claimed at least once. A build requeued after a transient
                // failure has started, whatever its status reads now.
                'started' => $build !== null && $build->attempts > 0,
            ];
        }

        return $answer;
    }

    /**
     * The order a service was bought on, if it was bought on one.
     */
    public function orderBehind(?string $serviceId): ?string
    {
        if ($serviceId === null) {
            return null;
        }

        $orderId = Service::query()->whereKey($serviceId)->value('order_id');

        return $orderId === null ? null : (string) $orderId;
    }

    /**
     * @return list<string>
     */
    private static function buildKinds(): array
    {
        return array_values(array_map(
            static fn (ProvisioningJobKind $kind): string => $kind->value,
            array_filter(
                ProvisioningJobKind::cases(),
                static fn (ProvisioningJobKind $kind): bool => $kind->createsResource(),
            ),
        ));
    }
}
