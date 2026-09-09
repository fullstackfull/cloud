<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\DTOs;

use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;

/**
 * What the readiness engine is allowed to know about one provider.
 *
 * A projection rather than the model, so the engine is a pure function of
 * stated facts and its tests need no database. Everything here is something
 * the Providers module already decided; nothing is re-derived.
 */
final readonly class ProviderFacts
{
    /**
     * @param  bool  $controlled  A fake, rehearsal-only driver. Counts for testing and for nothing above it.
     * @param  array<string, CapabilityState>  $capabilities  What a connection test observed, keyed by capability.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ProviderCategory $category,
        public string $driver,
        public bool $controlled,
        public DeploymentEnvironment $environment,
        public ProviderState $state,
        public ReadinessState $readiness,
        public ?BlockerReason $blocker,
        public array $capabilities,
    ) {}

    public function supports(string $capability): bool
    {
        return ($this->capabilities[$capability] ?? null) === CapabilityState::Supported;
    }

    /**
     * Proven: something answered, its capabilities are known, and nobody has
     * switched it off. The same bar the Providers module sets for enabling.
     */
    public function isProven(): bool
    {
        return $this->readiness === ReadinessState::ReadyForProduction
            && in_array($this->state, [ProviderState::Ready, ProviderState::Enabled], strict: true);
    }
}
