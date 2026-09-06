<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Provisioning\Domain\Events\DriftRecorded;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;

/**
 * Records that the platform and a provider disagree — and does nothing else.
 *
 * Two decisions are deliberate.
 *
 * First, this never auto-heals. Not the missing machine, not the orphan, not
 * the mismatched disk size. Every automated remedy for drift is one bug away
 * from deleting production: a reconciler that "fixes" a machine the provider
 * has not listed yet destroys a customer's server because an API paged badly.
 * The platform therefore records, alerts, and waits for a person, and
 * `provisioning.reconciliation.auto_heal` exists as a permanently false flag
 * so that this is a stated decision rather than an omission.
 *
 * Second, a repeat sighting updates the existing row instead of inserting a
 * new one. A reconciler running every thirty minutes turns one unnoticed
 * orphan into forty-eight rows a day, and an alert channel that cries wolf
 * forty-eight times is one nobody reads on the day it matters.
 *
 * The de-duplication is serialised on a transaction-scoped advisory lock keyed
 * by the drift's identity, because resource_drifts has no unique index over
 * (provider, resource_type, kind, provider_reference) to fall back on. A
 * SELECT-then-INSERT without it would let two reconcilers running in parallel
 * insert the same drift twice, which is precisely the outcome this action
 * exists to prevent.
 */
final readonly class RecordDrift
{
    /**
     * @param  array<string, mixed>|null  $expected  What the platform believes.
     * @param  array<string, mixed>|null  $observed  What the provider reports.
     */
    public function execute(
        string $provider,
        string $resourceType,
        DriftKind $kind,
        ?string $providerReference = null,
        ?string $serviceId = null,
        ?array $expected = null,
        ?array $observed = null,
        DriftSeverity $severity = DriftSeverity::Warning,
    ): ResourceDrift {
        return DB::transaction(function () use (
            $provider,
            $resourceType,
            $kind,
            $providerReference,
            $serviceId,
            $expected,
            $observed,
            $severity,
        ): ResourceDrift {
            $this->lockIdentity($provider, $resourceType, $kind, $providerReference, $serviceId);

            $existing = $this->existing($provider, $resourceType, $kind, $providerReference, $serviceId);

            if ($existing !== null) {
                $existing->occurrences++;
                $existing->last_seen_at = now();
                $existing->observed = $observed;

                if ($expected !== null) {
                    $existing->expected = $expected;
                }

                // Never downgraded by a later sighting: a drift that has once
                // been judged critical does not become a warning because the
                // next pass was less sure.
                if ($severity->isAtLeast($existing->severity)) {
                    $existing->severity = $severity;
                }

                $existing->save();

                return $existing;
            }

            $drift = ResourceDrift::create([
                'provider' => $provider,
                'resource_type' => $resourceType,
                'service_id' => $serviceId,
                'provider_reference' => $providerReference,
                'kind' => $kind,
                'severity' => $severity,
                'status' => DriftStatus::Open,
                'expected' => $expected,
                'observed' => $observed,
                'occurrences' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            // Only the first sighting is announced; the row's occurrence count
            // carries the rest.
            event(new DriftRecorded(
                driftId: (string) $drift->getKey(),
                provider: $provider,
                resourceType: $resourceType,
                kind: $kind,
                severity: $severity,
                serviceId: $serviceId,
                providerReference: $providerReference,
            ));

            return $drift;
        });
    }

    /**
     * Serialise everyone who is about to record this same disagreement.
     *
     * The lock is transaction-scoped, so it is released by the commit or the
     * rollback and cannot be leaked by a worker that dies mid-sync.
     */
    private function lockIdentity(
        string $provider,
        string $resourceType,
        DriftKind $kind,
        ?string $providerReference,
        ?string $serviceId,
    ): void {
        $identity = implode('|', [
            'drift',
            $provider,
            $resourceType,
            $kind->value,
            $providerReference ?? '',
            $serviceId ?? '',
        ]);

        DB::statement('select pg_advisory_xact_lock(hashtext(?))', [$identity]);
    }

    private function existing(
        string $provider,
        string $resourceType,
        DriftKind $kind,
        ?string $providerReference,
        ?string $serviceId,
    ): ?ResourceDrift {
        return ResourceDrift::query()
            ->where('provider', $provider)
            ->where('resource_type', $resourceType)
            ->where('kind', $kind->value)
            ->where(fn (Builder $query): Builder => $providerReference === null
                ? $query->whereNull('provider_reference')
                : $query->where('provider_reference', $providerReference))
            ->where(fn (Builder $query): Builder => $serviceId === null
                ? $query->whereNull('service_id')
                : $query->where('service_id', $serviceId))
            // A resolved drift that reappears starts a new row: the resolution
            // that did not hold is history worth keeping separate.
            ->whereIn('status', [DriftStatus::Open->value, DriftStatus::Acknowledged->value])
            ->orderByDesc('last_seen_at')
            ->first();
    }
}
