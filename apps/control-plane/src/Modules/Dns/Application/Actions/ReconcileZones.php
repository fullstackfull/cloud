<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Actions;

use Illuminate\Database\QueryException;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Enums\IndeterminateAfter;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsNotConfiguredException;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsProviderException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDnsRecordException;
use Lynomia\Modules\Dns\Domain\Services\DnsRecordIdentity;
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
 *  - **A deletion that completed after all.** A row left `deleting`, or left
 *    `indeterminate` by a *delete* that did not answer, whose value is no
 *    longer in the zone. Settled to `deleted`: this is the answer the
 *    platform was waiting for, and the Timeout Rule says wait for it rather
 *    than ask again.
 *  - **A publish that landed after all.** A row left `indeterminate` by a
 *    *publish*, whose value is in the zone saying exactly what the platform
 *    meant it to say. Settled to `active`, claiming the provider's own
 *    identifier through `ClaimProviderRecord` — the same claim a publish
 *    makes, for the same reason.
 *  - **Records nobody here wrote.** Reported and left alone, for ever.
 *
 * Which call left a row indeterminate is recorded on the row
 * (`indeterminate_after`), because the same observation means opposite things
 * for the two calls: see `IndeterminateAfter`. A publish that never arrived is
 * **not** a deletion, and a delete that never arrived is **not** a record
 * going live — it goes to `needs_review`, because the customer asked for the
 * record to be gone and it is still answering. A row that cannot say which
 * call it was waiting on is left for a person rather than guessed at.
 *
 * Records are matched by identity (`DnsRecordIdentity`'s two tiers, plus
 * the rule that a match must say what the row says), and each record in the
 * zone is matched at most once, by position in the listing. A key of
 * `type|name|content` used to stand in for that, and it collapsed an MX at
 * two priorities, or two CAA records whose fields differed, onto one key — so
 * a certificate authority added in a provider's console beside the one the
 * platform wrote was silently counted as the platform's.
 */
final readonly class ReconcileZones
{
    private const string RESOURCE = 'dns_record';

    private const string ZONE_RESOURCE = 'dns_zone';

    public function __construct(
        private DnsProviderFactory $providers,
        private RecordDrift $drift,
        private ClaimProviderRecord $claim,
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
            // Rows that already hold an identifier first, so that a row
            // claiming a record by value never takes it from the row whose
            // identifier names it.
            ->orderByRaw('provider_record_id is null')
            ->orderBy('id')
            ->get()
            ->all();

        /** @var array<int, true> $matched positions in $present */
        $matched = [];

        foreach ($rows as $row) {
            $position = $this->find($row, $present, $matched);
            $found = $position === null ? null : $present[$position];

            if ($position !== null) {
                $matched[$position] = true;
            }

            try {
                if ($this->settle($row, $found)) {
                    $settled++;

                    continue;
                }
            } catch (QueryException $e) {
                /*
                 * One row the table will not take — `ClaimProviderRecord` is
                 * meant to make this unreachable, and the unique index is
                 * what makes it loud if something bypasses the claim — must
                 * not abort the whole pass. The pass is the very channel that
                 * reports a disowned row, and every other zone in the batch
                 * would go unlooked-at behind it.
                 */
                report($e);

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
     * The questions a listing answers rather than raises.
     *
     * All are about rows the platform stopped being sure of, and in all of
     * them the zone itself is the authority: it is the thing serving the name.
     * Each needs to know which call the row was waiting on — the same
     * observation settles a publish and a delete in opposite directions.
     */
    private function settle(DnsRecord $row, ?DnsRecordValue $found): bool
    {
        $after = $row->indeterminate_after;

        if ($found !== null && $row->state === DnsState::Indeterminate) {
            if ($after === IndeterminateAfter::Publish) {
                $this->claim->execute($row, DnsState::Active, $found->id(), [
                    'failure_reason' => null,
                    'last_published_at' => now(),
                ]);

                return true;
            }

            if ($after === IndeterminateAfter::Delete) {
                // The delete did not land: the record the customer asked to
                // remove is still answering. Not `active` — that would stamp
                // it live and clear the evidence — and not retried from here,
                // because this sweep never writes to a zone. A person, or the
                // customer deleting again, finishes it.
                $row->transitionTo(DnsState::NeedsReview, [
                    'failure_reason' => 'The delete did not answer, and the record is still in the zone.',
                ]);

                return true;
            }

            return false;
        }

        if ($found === null && $row->state === DnsState::Deleting) {
            $row->transitionTo(DnsState::Deleted, ['failure_reason' => null]);

            return true;
        }

        if ($found === null && $row->state === DnsState::Indeterminate && $after === IndeterminateAfter::Delete) {
            $row->transitionTo(DnsState::Deleted, ['failure_reason' => null]);

            return true;
        }

        /*
         * Indeterminate after a publish, value absent: the record never
         * arrived. Not a deletion — the customer never asked for it to go —
         * so it stays where it is, visible to the customer and to operations
         * through `needsAttention()`. It is not written up as drift: which
         * kind of drift it is remains an open product decision.
         */
        return false;
    }

    /**
     * Which record in the listing this row is, by position, or null.
     *
     * The record under the row's identifier if the zone holds it and it says
     * what the row says; otherwise a record holding the row's value that no
     * other row has matched. A record under the row's identifier that says
     * something else is *not* this row — an edit that did not land, or a
     * value changed in the provider's console — and calling it a match would
     * settle a row as live on a value the zone is not serving.
     *
     * @param  list<DnsRecordValue>  $present
     * @param  array<int, true>  $matched
     */
    private function find(DnsRecord $row, array $present, array $matched): ?int
    {
        try {
            $wanted = $row->toValue();
        } catch (InvalidDnsRecordException) {
            // A row that cannot be built was never sent, so nothing in the
            // zone can be it.
            return null;
        }

        $unmatched = array_filter($present, static fn (int $position): bool => ! isset($matched[$position]), ARRAY_FILTER_USE_KEY);

        $found = DnsRecordIdentity::findAmong($wanted, array_values($unmatched));

        foreach ($unmatched as $position => $candidate) {
            if ($candidate === $found && $found->saysTheSameAs($wanted)) {
                return $position;
            }
        }

        // The identifier tier found a record that no longer says this, or
        // nothing: the value alone may still find one that does.
        foreach ($unmatched as $position => $candidate) {
            if ($candidate->saysTheSameAs($wanted)) {
                return $position;
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
     * @param  array<int, true>  $matched
     */
    private function reportStrangers(DnsZone $zone, array $present, array $matched): int
    {
        $count = 0;

        foreach ($present as $position => $candidate) {
            if (isset($matched[$position])) {
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

    /**
     * A reference for a record the provider gave no identifier, naming every
     * field that makes it the record it is — priority and structured data
     * included, so two different records never share one.
     */
    private function keyOf(DnsRecordValue $record): string
    {
        return implode('|', [
            $record->type()->value,
            $record->name(),
            $record->content(),
            (string) $record->priority(),
            (string) json_encode($record->data()),
        ]);
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
