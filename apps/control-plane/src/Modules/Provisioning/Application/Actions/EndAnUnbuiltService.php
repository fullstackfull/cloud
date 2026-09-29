<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Application\Services\EvidenceOfABuild;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Exceptions\ABuildMayExistException;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;

/**
 * A service ends because nothing was built for it.
 *
 * Called by each kind's termination at the point where it used to refuse — a
 * VPS with no machine row, a dedicated service with no server, a hosting
 * service with no account the panel ever created — once EvidenceOfABuild has
 * found nothing in the build history that could exist at a provider. There is
 * nothing to destroy, so no provider is asked anything; the service simply
 * reaches `terminated`, and what it was bought on ends with it (F-19). That is
 * the whole of the change: before, such a purchase could never end, and the
 * plan unit and coupon hold it carried were never given back.
 *
 * No retention window applies, and `force` is not consulted: the window
 * protects a customer's data, and a build that left nothing left no data.
 *
 * The evidence is read under the build jobs' row locks, taken before the
 * service's — the order RetryProvisioningJob takes them in — so an operator's
 * retry cannot requeue a build between the answer "nothing was built" and the
 * ending it permits. And once the service has ended, that retry refuses
 * outright rather than build a machine for a purchase that is over.
 */
final readonly class EndAnUnbuiltService
{
    public function __construct(
        private EvidenceOfABuild $evidence,
        private TransitionService $transitionService,
    ) {}

    /**
     * @throws ABuildMayExistException
     * @throws IllegalStateTransitionException
     */
    public function execute(Service $service): Service
    {
        return DB::transaction(function () use ($service): Service {
            $this->evidence->assertNothingWasBuiltFor($service, lock: true);

            return $this->transitionService->execute($service, ServiceStatus::Terminated);
        });
    }
}
