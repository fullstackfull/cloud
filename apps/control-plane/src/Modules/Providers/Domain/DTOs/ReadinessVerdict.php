<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\DTOs;

use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;

/**
 * How far along a provider is, and the one thing stopping it going further.
 *
 * One blocker, not a list, and that is a decision rather than a simplification.
 * A screen showing six reasons a provider is not ready gives an operator six
 * places to start and no order; a screen showing the first one in dependency
 * order gives them the thing that must be fixed before any of the others can
 * even be assessed. Fixing it reveals the next.
 */
final readonly class ReadinessVerdict
{
    public function __construct(
        public ReadinessState $state,
        public ?BlockerReason $blocker,
        /** What is missing, in terms an operator can act on. Never a secret, never an endpoint's credentials. */
        public string $detail,
    ) {}

    public function isReady(): bool
    {
        return $this->state === ReadinessState::ReadyForProduction;
    }

    public static function ready(): self
    {
        return new self(ReadinessState::ReadyForProduction, null, 'Tested, and its capabilities are known.');
    }
}
