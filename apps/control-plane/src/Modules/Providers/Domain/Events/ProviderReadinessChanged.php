<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Events;

use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;

/**
 * A provider's readiness, blocker or state moved.
 *
 * Raised by the one place that decides those (AssessProvider) and by the two
 * operator decisions that change state without reassessing (enable, disable).
 * What listens is whatever leans on providers being ready — today, product
 * readiness — so a credential revoked here becomes a blocker on the products
 * that need it in the same transaction, not at the next nightly sweep.
 */
final readonly class ProviderReadinessChanged
{
    public function __construct(
        public string $providerId,
        public string $name,
        public ProviderCategory $category,
        public DeploymentEnvironment $environment,
        public ProviderState $state,
        public ReadinessState $readiness,
        public ?BlockerReason $blocker,
    ) {}
}
