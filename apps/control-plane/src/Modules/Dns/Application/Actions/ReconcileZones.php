<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Actions;

use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsNotConfiguredException;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsProviderException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDnsRecordException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord as DnsRecordValue;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsZone as DnsZoneValue;
use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Provisioning\Application\Actions\RecordDrift;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;

/**
 * Compare what the platform believes a zone says with what it says.
 *
 * ---------------------------------------------------------------------------
 * It never writes to a zone
 * ---------------------------------------------------------------------------
 *
 * Not once, in any of the cases below. That is the whole design, and it is not
 * timidity: a customer's zone is not this platform's document. People add
 * records through a provider's own console, through Terraform, through their
 * previous host's migration tool. A sweep that deleted what it did not
 * recognise would be a sweep that deletes a customer's mail routing at three
 * in the morning because it was not written here — and it would be *right*
 * about the record not being in this database, which is what makes it such a
 * convincing way to lose somebody's business.
 *
 * So the four disagreements are reported, and two of them are also *settled*,
 * which is different: settling means the platform stops claiming something it
 * can now see is untrue about its own row. It is a write to this database, and
 * never to the zone.
 *
 *  - **Missing at the provider.** The platform says a record is live and the
 *    zone does not have it. Critical — the name is not resolving, and the
 *    customer's screen says it is.
 *  - **A deletion that completed after all.** A row left `deleting` or
 *    `indeterminate` by a call that did not answer, whose value is no longer
 *    in the zone. Settled to `deleted`: this is the answer the platform was
 *    waiting for, and the Timeout Rule says wait for it rather than ask again.
 *  - **A publish that landed after all.** A row left `indeterminate` whose
 *    value *is* in the zone, saying exactly what the platform meant it to say.
 *    Settled to `active`, with the provider's own identifier picked up.
 *  - **Records nobody here wrote.** Reported and left alone, for ever.
 */
final readonly class ReconcileZones
{
    private const string RESOURCE = 'dns_record';

    private const string ZONE_RESOURCE = 'dns_zone';

    public function __construct(
        private DnsProviderFactory $providers,
        private RecordDrift $drift,
    ) {}

    /**
     * @return array{zones: int, drifts: int, settled: int}
     */
    public function execute(): array
    {
        $zones = 0;
        $drifts = 0;
        $settled = 0;

        foreach ($this->stale() as $zone) {
            $provider = $this->providers->make();

            if ($zone->provider_zone_id === null) {
                continue;
            }

            try {
                $present = $provider->records(
                    DnsZoneValue::of($zone->provider_zone_id, $zone->name, $zone->nameservers ?? []),
                );
            } catch (DnsProviderException|DnsNotConfiguredException) {
                /*
                 * A provider that will not answer is not drift. Nothing is
                 * concluded, nothing is stamped, and the zone stays stale so
                 * the next run looks at it again — a sweep that recorded
                 * "missing" every time an API was down would fill an
                 * operator's queue with its own outage.
                 */
                continue;
            }

            $zones++;

            $outcome = $this->compare($zone, $present);

            $drifts += $outcome['drifts'];
            $settled += $outcome['settled'];

            $zone->forceFill(['last_synced_at' => now()])->save();
        }

        $drifts += $this->reportUnknownZones();

        return ['zones' => $zones, 'drifts' => $drifts, 'settled' => $settled];
    }

    /**
     * Zones at the provider that this platform has no live row for.
     *
     * The case that makes this worth an extra call per run: a claim that timed
     * out may well have created the zone, and the platform never learned its
     * identifier. Nothing else would ever notice it — it is not in the local
     * table, so no per-zone check reaches it — and it sits at the provider
     * being billed for and, worse, quietly serving whatever the last delegation
     * pointed at.
     *
     * Reported and never removed, for the same reason as everything else here:
     * a deployment may share a provider account with something that is not
     * this platform.
     */
    private function reportUnknownZones(): int
    {
        try {
            $held = $this->providers->make()->zones();
        } catch (DnsProviderException|DnsNotConfiguredException) {
            return 0;
        }

        /** @var list<string> $known */
        $known = DnsZone::query()
            ->where('state', '!=', DnsState::Deleted->value)
            ->pluck('name')
            ->all();

        $count = 0;

        foreach ($held as $zone) {
            if (in_array($zone->name(), $known, strict: true)) {
                continue;
            }

            $this->drift->execute(
                provider: $this->providers->make()->name(),
                resourceType: self::ZONE_RESOURCE,
                kind: DriftKind::OrphanAtProvider,
                providerReference: $zone->id(),
                expected: ['known_to_platform' => false],
                observed: ['present' => true],
                severity: DriftSeverity::Warning,
            );

            $count++;
        }

        return $count;
    }

    /**
     * @param  list<DnsRecordValue>  $present
     * @return array{drifts: int, settled: int}
     */
    private function compare(DnsZone $zone, array $present): array
    {
        $drifts = 0;
        $settled = 0;

        /** @var list<DnsRecord> $rows */
        $rows = DnsRecord::query()
            ->where('dns_zone_id', $zone->getKey())
            ->where('state', '!=', DnsState::Deleted->value)
            ->get()
            ->all();

        $matched = [];

        foreach ($rows as $row) {
            $found = $this->find($row, $present);

            if ($found !== null) {
                $matched[] = $this->keyOf($found);
            }

            if ($this->settle($row, $found)) {
                $settled++;

                continue;
            }

            if ($row->state === DnsState::Active && $found === null) {
                $this->drift->execute(
                    provider: $zone->provider,
                    resourceType: self::RESOURCE,
                    kind: DriftKind::MissingAtProvider,
                    providerReference: (string) ($row->provider_record_id ?? $row->getKey()),
                    serviceId: (string) ($zone->service_id ?? ''),
                    expected: ['state' => $row->state->value, 'type' => $row->type->value],
                    observed: ['present' => false],
                    // The name is not resolving and the platform is telling
                    // the customer that it is.
                    severity: DriftSeverity::Critical,
                );

                $drifts++;
            }
        }

        $drifts += $this->reportStrangers($zone, $present, $matched);

        return ['drifts' => $drifts, 'settled' => $settled];
    }

    /**
     * The two questions a listing answers rather than raises.
     *
     * Both are about rows the platform stopped being sure of, and in both the
     * zone itself is the authority: it is the thing serving the name.
     */
    private function settle(DnsRecord $row, ?DnsRecordValue $found): bool
    {
        if ($found !== null && $row->state === DnsState::Indeterminate) {
            $row->transitionTo(DnsState::Active, [
                'provider_record_id' => $found->id(),
                'failure_reason' => null,
                'last_published_at' => now(),
            ]);

            return true;
        }

        if ($found === null && ($row->state === DnsState::Deleting || $row->state === DnsState::Indeterminate)) {
            $row->transitionTo(DnsState::Deleted, ['failure_reason' => null]);

            return true;
        }

        return false;
    }

    /**
     * @param  list<DnsRecordValue>  $present
     */
    private function find(DnsRecord $row, array $present): ?DnsRecordValue
    {
        try {
            $wanted = $row->toValue();
        } catch (InvalidDnsRecordException) {
            // A row that cannot be built was never sent, so nothing in the
            // zone can be it.
            return null;
        }

        foreach ($present as $candidate) {
            if ($candidate->saysTheSameAs($wanted)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Records in the zone that this platform did not write.
     *
     * Reported once each, at warning rather than critical, and never touched.
     * Most of them are somebody's legitimate work through another tool; the
     * rest are the interesting ones, and an operator is the only thing that
     * can tell the difference.
     *
     * @param  list<DnsRecordValue>  $present
     * @param  list<string>  $matched
     */
    private function reportStrangers(DnsZone $zone, array $present, array $matched): int
    {
        $count = 0;

        foreach ($present as $candidate) {
            if (in_array($this->keyOf($candidate), $matched, strict: true)) {
                continue;
            }

            $this->drift->execute(
                provider: $zone->provider,
                resourceType: self::RESOURCE,
                kind: DriftKind::OrphanAtProvider,
                providerReference: (string) ($candidate->id() ?? $this->keyOf($candidate)),
                serviceId: (string) ($zone->service_id ?? ''),
                expected: ['known_to_platform' => false],
                observed: ['present' => true, 'type' => $candidate->type()->value],
                severity: DriftSeverity::Warning,
            );

            $count++;
        }

        return $count;
    }

    private function keyOf(DnsRecordValue $record): string
    {
        return $record->type()->value.'|'.$record->name().'|'.$record->content();
    }

    /**
     * Zones this run will look at: live ones, oldest picture first.
     *
     * Bounded because a provider's API has a rate limit, and a sweep that hits
     * it reconciles nothing at all — the batch is the difference between a
     * slow full pass and no pass.
     *
     * @return list<DnsZone>
     */
    private function stale(): array
    {
        $after = now()->subHours(max(1, (int) config('dns.reconcile_after_hours', 6)));

        /** @var list<DnsZone> $zones */
        $zones = DnsZone::query()
            ->where('state', DnsState::Active->value)
            ->where(static function ($query) use ($after): void {
                $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $after);
            })
            ->orderByRaw('last_synced_at asc nulls first')
            ->limit(max(1, (int) config('dns.reconcile_batch', 50)))
            ->get()
            ->all();

        return $zones;
    }
}
