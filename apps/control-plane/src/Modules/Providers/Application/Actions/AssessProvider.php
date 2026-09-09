<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Actions;

use Lynomia\Modules\Providers\Domain\DTOs\ReadinessVerdict;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\Providers\Domain\Services\ProviderReadiness;
use Lynomia\Modules\Providers\Infrastructure\ConnectionTesterFactory;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;

/**
 * Work out where a provider stands, and write it down.
 *
 * ---------------------------------------------------------------------------
 * Why readiness is stored rather than computed on read
 * ---------------------------------------------------------------------------
 *
 * Because it is queried across the whole estate — "what is blocking us" is a
 * list screen, and computing it per row would mean loading every provider's
 * server, licence, credential and capabilities to render a table. Storing it
 * costs one recomputation at each point something could have changed it, and
 * those points are few and known: registration, a connection test, a
 * credential or licence change, and an operator asking.
 *
 * The cost of storing it is that it can go stale, which is a real risk and the
 * reason this is a separate action every one of those paths calls rather than
 * a line copied into each of them.
 *
 * ---------------------------------------------------------------------------
 * What it does not do
 * ---------------------------------------------------------------------------
 *
 * It never enables anything. Readiness reaching ReadyForProduction makes
 * enabling permissible and does not perform it — a payment provider that went
 * live because a key finished validating is exactly the accident this module
 * is shaped to prevent.
 *
 * It does not demote an Enabled provider to Blocked either. That decision
 * belongs to an operator, because switching a provider off stops new work
 * reaching it, and doing that automatically on a transient blocker would turn
 * a five-minute network fault into a halt in sales. The readiness and blocker
 * columns tell the truth; the state stays where a person put it.
 */
final readonly class AssessProvider
{
    public function __construct(
        private ProviderCatalogue $catalogue,
        private ProviderReadiness $readiness,
        private ConnectionTesterFactory $testers,
    ) {}

    public function execute(ProviderInstance $provider): ReadinessVerdict
    {
        $entry = $this->catalogue->find($provider->driver);

        if ($entry === null) {
            /*
             * A row whose driver has since left the catalogue. Registration
             * refuses unknown drivers, so this means an adapter was removed
             * while instances of it still existed — worth saying plainly
             * rather than crashing a list screen.
             */
            $verdict = new ReadinessVerdict(
                ReadinessState::NotReady,
                BlockerReason::Dependency,
                sprintf('The %s adapter no longer exists in this build.', $provider->driver),
            );
        } else {
            $verdict = $this->readiness->assess(
                $entry,
                $provider,
                $this->testers->handles($provider->driver),
            );
        }

        $provider->forceFill([
            'readiness' => $verdict->state,
            'blocker' => $verdict->blocker,
            // A provider an operator has not touched follows its own
            // assessment between Draft, Ready and Blocked. One that is Enabled
            // or Disabled stays where it was put, for the reason above.
            'state' => match (true) {
                $provider->state === ProviderState::Enabled, $provider->state === ProviderState::Disabled => $provider->state,
                $verdict->isReady() => ProviderState::Ready,
                $verdict->blocker !== null => ProviderState::Blocked,
                default => ProviderState::Draft,
            },
        ])->save();

        return $verdict;
    }
}
