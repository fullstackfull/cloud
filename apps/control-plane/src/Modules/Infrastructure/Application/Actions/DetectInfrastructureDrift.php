<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Infrastructure\Domain\Enums\ServerState;
use Lynomia\Modules\Infrastructure\Domain\Services\PlanEngine;
use Lynomia\Modules\Infrastructure\Domain\Services\SoftwareCatalogue;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ServerFact;
use Lynomia\Modules\Provisioning\Application\Actions\RecordDrift;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;

/**
 * A managed machine that no longer matches its profile is drift, and it goes
 * where every other drift goes: the drift queue, worst first, for a person.
 *
 * Contacts nothing. What it compares is the desired state against the facts
 * the last verification wrote — a machine whose `software.<x>.present` fact
 * was superseded by a later look that did not see the component, or that a
 * verify run reported absent. A machine that was never brought to its
 * profile is not drift; it is work. Only Managed machines are examined.
 *
 * Recorded through the Provisioning module's own action so the row carries
 * the same identity rules (one open row per subject and kind, occurrences
 * counted, severity never downgraded) and reaches the same queue, screen and
 * metric as compute and hosting drift.
 */
final readonly class DetectInfrastructureDrift
{
    public function __construct(
        private SoftwareCatalogue $catalogue,
        private PlanEngine $engine,
        private RecordDrift $record,
    ) {}

    /**
     * @return array{examined: int, drifted: int}
     */
    public function execute(): array
    {
        $servers = ManagedServer::query()
            ->where('state', ServerState::Managed->value)
            ->whereHas('desiredState')
            ->with('desiredState.profile')
            ->get();

        $drifted = 0;

        foreach ($servers as $server) {
            if ($this->forServer($server)) {
                $drifted++;
            }
        }

        return ['examined' => $servers->count(), 'drifted' => $drifted];
    }

    /**
     * Whether this machine has drifted from its profile; records it if so.
     */
    public function forServer(ManagedServer $server): bool
    {
        $desired = $server->desiredState()->with('profile')->first();

        if ($desired === null || $server->state !== ServerState::Managed) {
            return false;
        }

        $profile = $this->catalogue->profile((string) $desired->profile?->key);

        if ($profile === null) {
            return false;
        }

        $facts = ServerFact::query()->where('managed_server_id', $server->getKey())->current()->pluck('value', 'key')->all();

        /** @var array<string, string> $overrides */
        $overrides = $desired->overrides ?? [];

        // Licences are not the question here — a lapsed licence is the
        // Licence Center's finding — so every product is treated as licensed
        // and only presence decides.
        $plan = $this->engine->plan($profile, $overrides, $facts, $server->safety_class, $server->allow_reimage, array_map(
            static fn ($c): string => (string) $c->licenceProduct,
            $this->catalogue->componentsOf($profile),
        ));

        if ($plan->changes === []) {
            return false;
        }

        $missing = array_map(static fn ($change): string => $change->component, $plan->changes);

        $this->record->execute(
            provider: 'infrastructure',
            resourceType: 'managed_server',
            kind: DriftKind::SpecMismatch,
            providerReference: $server->name,
            expected: ['profile' => $profile->key, 'components' => $profile->components],
            observed: ['absent' => $missing],
            severity: DriftSeverity::Warning,
        );

        return true;
    }
}
