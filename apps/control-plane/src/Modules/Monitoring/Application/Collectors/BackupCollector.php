<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;

/**
 * Whether the platform's backups exist, and whether they are worth anything.
 *
 * ===========================================================================
 * WHY THIS FILE EXISTS
 * ===========================================================================
 *
 * `infrastructure/monitoring/prometheus/rules/backups.yml` has held six alerts
 * since Phase 30B. Two of them — `BackupVerificationFailed` and
 * `BackupVerificationStale` — are the only things in the entire platform that
 * distinguish "we have backups" from "we have files named after backups".
 *
 * Nothing produced the series they read. Six alerts, zero producers. Every one
 * of them evaluated over an absent metric, which in Prometheus is not an error
 * and not a warning: it is silence. A rule that reads a series nobody writes
 * looks exactly like a rule that is passing, on the dashboard and in the
 * alert list and in any review that does not go and check.
 *
 * `infrastructure/README.md` has named that gap on every CI run since it was
 * found, and `docs/phase-30b-0-real-infrastructure-preflight.md` recorded it
 * as a risk rather than a cosmetic omission. This closes it.
 *
 * ===========================================================================
 * THE CONTRACT IS THE ALERTS', NOT THIS FILE'S
 * ===========================================================================
 *
 * Every metric name below is read verbatim out of `backups.yml`. None was
 * invented here and none may be renamed here: if a name changes, the alert
 * that reads it stops firing, silently, and the whole failure returns. The
 * architecture test `EveryBackupAlertMetricHasAProducerTest` asserts the two
 * sets are equal in both directions, so an alert cannot acquire a metric
 * nobody writes and this collector cannot quietly stop writing one.
 *
 * ===========================================================================
 * WHERE THE NUMBERS COME FROM, AND WHY NOT FROM PBS
 * ===========================================================================
 *
 * The comment at the top of `backups.yml` anticipated a node_exporter textfile
 * collector running *on the backup server*. That is a reasonable design and it
 * is not the one implemented, for a reason worth writing down: a textfile
 * collector on PBS can only report what PBS did. It cannot report a backup the
 * control plane asked for and never heard about, which is the failure that
 * matters most — and it cannot run at all until a PBS host exists, which as of
 * `30B.0` it does not.
 *
 * The control plane, by contrast, already records every backup it requested,
 * every task id it polled, and every verification result it was told about, in
 * the `backups` table. So these series are produced from the platform's own
 * record of reality. When a real PBS appears, a textfile collector may be
 * added *beside* this one to cross-check it; the two disagreeing would itself
 * be worth an alert.
 *
 * ===========================================================================
 * CARDINALITY AND PRIVACY
 * ===========================================================================
 *
 * The alerts' annotations originally referenced a `guest_name` label. This
 * collector deliberately does not emit one, and the annotations were corrected
 * to match, because a guest's name is a customer's hostname and this series
 * ends up in whatever monitoring system scrapes the platform, in every
 * dashboard built on it, and in every alert notification it sends. `DnsCollector`
 * refuses per-domain labels for the same reason and says so.
 *
 * What is emitted instead is `guest_id`: the platform's own opaque identifier.
 * An operator who needs the hostname looks it up from that id, in the system
 * that is allowed to know it.
 *
 * Per-guest series do grow with the customer base, which the collector contract
 * warns about. They are kept because the entire operational value of
 * `BackupJobFailed` is naming *which* guest lost its backup, and because the
 * row count here is bounded by guests-with-backups rather than by guests. The
 * datastore-level metrics below are the ones that stay small.
 */
final readonly class BackupCollector implements MetricsCollector
{
    /**
     * States in which a backup is a real, usable snapshot.
     *
     * `Verified` and `Restored` are successes that happen to have had more
     * done to them. Excluding them would make a verified backup look like no
     * backup at all, which is the opposite of this file's purpose.
     */
    private const SUCCEEDED = [
        BackupState::Succeeded->value,
        BackupState::Verifying->value,
        BackupState::Verified->value,
        BackupState::Restoring->value,
        BackupState::Restored->value,
    ];

    /**
     * The status values `lynomia_backup_task_last_status` reports.
     *
     * Zero is the only value the alert treats as healthy (`!= 0`). Two states
     * are unhealthy for different reasons and are given different numbers so
     * that a dashboard can tell them apart without a second query — and
     * because `needs_review` is emphatically not `failed`: the platform does
     * not know what happened, which is its own condition and is handled by its
     * own operator workflow.
     */
    private const STATUS_OK = 0;

    private const STATUS_FAILED = 1;

    private const STATUS_NEEDS_REVIEW = 2;

    public function name(): string
    {
        return 'backups';
    }

    public function collect(): array
    {
        $guests = $this->perGuest();
        $datastores = $this->perDatastore();

        return [
            Metric::gauge(
                'lynomia_backup_task_last_status',
                'Outcome of the most recent backup task per guest: 0 succeeded, 1 failed, 2 needs review. `needs_review` is separate from `failed` because the platform does not know which one happened, and a human decides.',
                $this->taskStatus($guests),
            ),
            Metric::gauge(
                'lynomia_backup_last_success_timestamp_seconds',
                'Unix time of the last successful backup of each guest. Zero when a guest has a backup row but has never had one succeed — which is the case `BackupTooOld` must catch, and would miss entirely if the series were simply absent.',
                $this->lastSuccess($guests),
            ),
            Metric::gauge(
                'lynomia_backup_collector_last_run_timestamp_seconds',
                'Unix time this collector last ran. The metric that watches the metrics: without it a dead collector is indistinguishable from a platform where every backup is fine, because every other rule in backups.yml would evaluate over nothing.',
                [MetricSample::of([], time())],
            ),
            Metric::gauge(
                'lynomia_backup_verify_last_status',
                'Outcome of the most recent verification per snapshot group: 0 passed, 1 failed. Emitted only where verification has actually run — a group with no verification is reported by lynomia_backup_unverified_snapshots, not as a pass.',
                $this->verifyStatus(),
            ),
            Metric::gauge(
                'lynomia_backup_verify_last_run_timestamp_seconds',
                'Unix time verification last ran on each datastore. Zero when it has never run, so that `BackupVerificationStale` fires on a datastore nobody has ever verified rather than staying silent about it.',
                $datastores['verified_at'],
            ),
            Metric::gauge(
                'lynomia_backup_unverified_snapshots',
                'Live successful snapshots on each datastore whose verification has not run. These are the backups that look healthy in every listing and are not known to restore.',
                $datastores['unverified'],
            ),
        ];
    }

    /**
     * Both guest-level facts in one grouped query.
     *
     * The latest state and the last successful time are different aggregations
     * over the same grouping, so they come back together rather than as two
     * round trips. `array_agg(... ORDER BY created_at DESC)[1]` is PostgreSQL's
     * "value from the newest row in this group", which is what "the most recent
     * backup's outcome" means, and it costs one sort inside a group the planner
     * is already forming.
     *
     * A guest is identified by `virtual_machine_id` where there is one and by
     * `service_id` otherwise, so a product whose backups are not attached to a
     * VM still gets its own series instead of being merged under a null.
     *
     * One query. The number of queries here does not change with the number of
     * guests, backups or datastores — only the number of rows returned does,
     * which is what `the_query_count_does_not_grow_with_the_amount_of_data`
     * exists to hold.
     *
     * @return list<array{guest_type: string, guest_id: string, datastore: string, state: string, last_success: float}>
     */
    private function perGuest(): array
    {
        /** @var list<object{guest_id: string, guest_type: string, datastore: string, latest_state: string, last_success: string|null}> $rows */
        $rows = DB::select(
            <<<'SQL'
                SELECT guest_id,
                       guest_type,
                       datastore,
                       (array_agg(state ORDER BY created_at DESC))[1] AS latest_state,
                       MAX(
                           CASE WHEN state = ANY(?)
                                THEN EXTRACT(EPOCH FROM COALESCE(finished_at, updated_at))
                           END
                       ) AS last_success
                  FROM (
                        SELECT COALESCE(virtual_machine_id, service_id) AS guest_id,
                               CASE WHEN virtual_machine_id IS NULL THEN 'service' ELSE 'qemu' END AS guest_type,
                               datastore,
                               state,
                               created_at,
                               finished_at,
                               updated_at
                          FROM backups
                         WHERE state <> ?
                       ) AS rows
                 GROUP BY guest_id, guest_type, datastore
                SQL,
            ['{'.implode(',', self::SUCCEEDED).'}', BackupState::Deleted->value],
        );

        return array_map(
            static fn (object $row): array => [
                'guest_type' => (string) $row->guest_type,
                'guest_id' => (string) $row->guest_id,
                'datastore' => (string) $row->datastore,
                'state' => (string) $row->latest_state,
                'last_success' => (float) ($row->last_success ?? 0),
            ],
            $rows,
        );
    }

    /**
     * @param  list<array{guest_type: string, guest_id: string, datastore: string, state: string, last_success: float}>  $guests
     * @return list<MetricSample>
     */
    private function taskStatus(array $guests): array
    {
        return array_map(
            fn (array $guest): MetricSample => MetricSample::of(
                $this->guestLabels($guest),
                $this->statusFor($guest['state']),
            ),
            $guests,
        );
    }

    /**
     * Every guest gets a last-success sample, including guests whose backups
     * have only ever failed.
     *
     * Zero is a deliberate value, not a missing one. `BackupTooOld` reads
     * `(time() - series) > 36h`, and against zero that is true — which is
     * exactly right for a guest with no successful backup, and is the case the
     * rule would miss entirely if the series were simply absent.
     *
     * @param  list<array{guest_type: string, guest_id: string, datastore: string, state: string, last_success: float}>  $guests
     * @return list<MetricSample>
     */
    private function lastSuccess(array $guests): array
    {
        return array_map(
            fn (array $guest): MetricSample => MetricSample::of(
                $this->guestLabels($guest),
                $guest['last_success'],
            ),
            $guests,
        );
    }

    /**
     * @param  array{guest_type: string, guest_id: string, datastore: string, state: string, last_success: float}  $guest
     * @return array<string, string>
     */
    private function guestLabels(array $guest): array
    {
        return [
            'datastore' => $guest['datastore'],
            'guest_type' => $guest['guest_type'],
            'guest_id' => $guest['guest_id'],
        ];
    }

    private function statusFor(string $state): int
    {
        if (in_array($state, self::SUCCEEDED, true)) {
            return self::STATUS_OK;
        }

        if ($state === BackupState::NeedsReview->value) {
            return self::STATUS_NEEDS_REVIEW;
        }

        if ($state === BackupState::Failed->value) {
            return self::STATUS_FAILED;
        }

        /*
         * `requested`, `running`, `delete_requested` and `deleting` are
         * in-flight rather than bad. Reporting them as failed would page
         * somebody every time a backup started; reporting them as succeeded
         * would be a lie. They report OK because the *last finished* outcome is
         * what the alert is about, and a guest whose only row is in flight is
         * caught by `BackupTooOld` through its zero success timestamp.
         */
        return self::STATUS_OK;
    }

    /**
     * Verification outcome per snapshot group, for groups where it has run.
     *
     * `verified` is nullable in the schema and the migration says why: not yet
     * verified is not the same as verified and failed. That distinction is the
     * whole subject of this collector, so a NULL is not folded into either
     * bucket here — it is counted by `unverifiedSnapshots()` instead.
     *
     * @return list<MetricSample>
     */
    private function verifyStatus(): array
    {
        /** @var list<object{guest_id: string, guest_type: string, datastore: string, verified: bool}> $rows */
        $rows = DB::select(
            <<<'SQL'
                SELECT DISTINCT ON (guest_id, datastore)
                       guest_id,
                       guest_type,
                       datastore,
                       verified
                  FROM (
                        SELECT COALESCE(virtual_machine_id, service_id) AS guest_id,
                               CASE WHEN virtual_machine_id IS NULL THEN 'service' ELSE 'qemu' END AS guest_type,
                               datastore,
                               verified,
                               verified_at
                          FROM backups
                         WHERE verified IS NOT NULL
                       ) AS verified_rows
                 ORDER BY guest_id, datastore, verified_at DESC
                SQL,
        );

        return array_map(
            static fn (object $row): MetricSample => MetricSample::of(
                [
                    'datastore' => (string) $row->datastore,
                    'snapshot_group' => $row->guest_type.'/'.$row->guest_id,
                ],
                $row->verified ? 0 : 1,
            ),
            $rows,
        );
    }

    /**
     * Both datastore-level facts in one grouped query.
     *
     * When verification last ran, and how many live successful snapshots have
     * not been verified, are two aggregations over the same grouping, so they
     * share a round trip.
     *
     * The zero for a never-verified datastore is the point of the first one.
     * `BackupVerificationStale` reads `(time() - series) > 8 days`, and its own
     * comment says it exists to fire when verification "has not run at all".
     * Against an absent series it fires for nobody; against zero it fires
     * immediately, which is the intended behaviour for a datastore holding
     * snapshots nobody has ever checked.
     *
     * @return array{verified_at: list<MetricSample>, unverified: list<MetricSample>}
     */
    private function perDatastore(): array
    {
        /** @var list<object{datastore: string, verified_at: string|null, unverified: int}> $rows */
        $rows = DB::select(
            <<<'SQL'
                SELECT datastore,
                       MAX(EXTRACT(EPOCH FROM verified_at)) AS verified_at,
                       COUNT(*) FILTER (
                           WHERE verified IS NULL AND state = ANY(?)
                       ) AS unverified
                  FROM backups
                 WHERE state <> ?
                 GROUP BY datastore
                SQL,
            ['{'.implode(',', self::SUCCEEDED).'}', BackupState::Deleted->value],
        );

        $verifiedAt = [];
        $unverified = [];

        foreach ($rows as $row) {
            $labels = ['datastore' => (string) $row->datastore];

            $verifiedAt[] = MetricSample::of($labels, (float) ($row->verified_at ?? 0));
            $unverified[] = MetricSample::of($labels, (int) $row->unverified);
        }

        return ['verified_at' => $verifiedAt, 'unverified' => $unverified];
    }
}
