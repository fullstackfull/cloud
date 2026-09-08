<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPolicy;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * What this service's plan says about backups.
 *
 * One place, because three callers need the same answer and would otherwise
 * each reach into the plan document their own way: the customer's screen
 * decides whether to offer Delete, the request path decides whether to accept
 * it, and the sweep decides what has expired. Two of those disagreeing would
 * mean a button that is refused, or worse, a sweep removing backups the screen
 * said were kept.
 *
 * A service with no plan — an operator-created row, a fixture — gets the
 * platform's own policy rather than an exception. Backups are not the place to
 * discover a missing catalogue link.
 */
final readonly class ResolveBackupPolicy
{
    public function execute(?Service $service): BackupPolicy
    {
        if ($service === null || $service->plan_id === null) {
            return BackupPolicy::default();
        }

        /** @var Plan|null $plan */
        $plan = Plan::query()->whereKey($service->plan_id)->first();

        if ($plan === null) {
            return BackupPolicy::default();
        }

        return BackupPolicy::fromPlanResources($plan->resources);
    }
}
