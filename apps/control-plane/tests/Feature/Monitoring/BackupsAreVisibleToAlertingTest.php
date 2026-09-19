<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Monitoring\Application\Collectors\BackupCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the backup alerts will actually see.
 *
 * The architecture gate beside this one proves every alerted metric has a
 * producer. That is necessary and not sufficient: a producer that emits the
 * right *names* with the wrong *values* satisfies it completely while still
 * reporting perfect health over a platform that cannot restore anything.
 *
 * So these are value assertions, written from the alert expressions backwards.
 * Each one names the rule it protects.
 */
final class BackupsAreVisibleToAlertingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_backup_that_exists_but_was_never_verified_is_counted_as_unverified(): void
    {
        /*
         * `UnverifiedSnapshotsAccumulating`, and the reason the whole file
         * exists. This snapshot succeeded. It has a size, a task id and a
         * green row in every listing. Nobody has checked that its chunks match
         * their checksums, so it is not known to restore.
         *
         * `verified` is NULL, which the migration deliberately distinguishes
         * from false. If a future change folded NULL into the pass bucket this
         * count would drop to zero and the platform would report that all is
         * well while knowing nothing at all.
         */
        Backup::factory()->succeeded()->create([
            'datastore' => 'vm-backups',
            'verified' => null,
            'verified_at' => null,
        ]);

        $this->assertSame(
            1.0,
            $this->sample('lynomia_backup_unverified_snapshots', ['datastore' => 'vm-backups']),
            'A successful, never-verified snapshot must be counted as unverified.',
        );

        $this->assertSame(
            [],
            $this->samplesOf('lynomia_backup_verify_last_status'),
            'A snapshot whose verification never ran must not appear in the verification-status series at all. '
            .'Emitting zero there would report it as a pass.',
        );
    }

    #[Test]
    public function a_datastore_nobody_has_ever_verified_reports_zero_so_the_stale_alert_fires(): void
    {
        /*
         * `BackupVerificationStale` reads `(time() - series) > 8 days`, and its
         * own comment says it exists to catch verification that "has not run at
         * all".
         *
         * Against an absent series that expression matches nothing and the
         * alert is silent. Against zero it is true immediately. Zero is
         * therefore the only correct value for a datastore nobody has verified,
         * and emitting nothing would be the bug.
         */
        Backup::factory()->succeeded()->create([
            'datastore' => 'vm-backups',
            'verified' => null,
            'verified_at' => null,
        ]);

        $this->assertSame(
            0.0,
            $this->sample('lynomia_backup_verify_last_run_timestamp_seconds', ['datastore' => 'vm-backups']),
            'A datastore with no verification history must report zero, not be absent.',
        );
    }

    #[Test]
    public function a_failed_verification_is_reported_as_failed_and_not_merely_as_unverified(): void
    {
        /*
         * `BackupVerificationFailed`, the one alert in the file that pages at
         * critical. The snapshot exists and its verification came back bad,
         * which is worse than not having checked: the platform knows the
         * chunks do not match.
         */
        Backup::factory()->succeeded()->create([
            'datastore' => 'vm-backups',
            'verified' => false,
            'verified_at' => now()->subHour(),
        ]);

        $failed = $this->samplesOf('lynomia_backup_verify_last_status');

        $this->assertCount(1, $failed);
        $this->assertSame(1.0, (float) $failed[0]->value, 'A failed verification must be non-zero so that `!= 0` fires.');
    }

    #[Test]
    public function a_passing_verification_reports_zero(): void
    {
        Backup::factory()->succeeded()->create([
            'datastore' => 'vm-backups',
            'verified' => true,
            'verified_at' => now()->subHour(),
        ]);

        $passing = $this->samplesOf('lynomia_backup_verify_last_status');

        $this->assertCount(1, $passing);
        $this->assertSame(0.0, (float) $passing[0]->value);

        $this->assertSame(
            0.0,
            $this->sample('lynomia_backup_unverified_snapshots', ['datastore' => 'vm-backups']),
            'A verified snapshot is not an unverified one.',
        );
    }

    #[Test]
    public function a_guest_whose_backups_have_only_ever_failed_reports_a_zero_last_success(): void
    {
        /*
         * `BackupTooOld`. The dangerous case is a guest that has never had a
         * successful backup at all: there is no success timestamp to be old.
         * Emitting no series would hide it from the one rule written to find
         * it, so zero is emitted and the rule fires.
         */
        Backup::factory()->create([
            'datastore' => 'vm-backups',
            'state' => BackupState::Failed->value,
            'verified' => null,
        ]);

        $this->assertSame(
            0.0,
            $this->firstSample('lynomia_backup_last_success_timestamp_seconds'),
            'A guest with no successful backup must report a zero last-success time, not be absent.',
        );

        $this->assertSame(
            1.0,
            $this->firstSample('lynomia_backup_task_last_status'),
            'A failed backup must report a non-zero task status.',
        );
    }

    #[Test]
    public function a_backup_needing_review_is_not_reported_as_a_failure(): void
    {
        /*
         * Async truth, carried from the customer portal: indeterminate is not
         * failed. The alert catches both through `!= 0`, and the distinct value
         * lets a dashboard tell an operator which one they are looking at
         * without a second query.
         */
        Backup::factory()->create([
            'datastore' => 'vm-backups',
            'state' => BackupState::NeedsReview->value,
            'verified' => null,
        ]);

        $this->assertSame(
            2.0,
            $this->firstSample('lynomia_backup_task_last_status'),
            'needs_review must be distinguishable from failed while still being non-zero.',
        );
    }

    #[Test]
    public function the_heartbeat_is_the_time_the_collector_ran(): void
    {
        $before = time();

        $heartbeat = $this->firstSample('lynomia_backup_collector_last_run_timestamp_seconds');

        $this->assertGreaterThanOrEqual((float) $before, $heartbeat);
        $this->assertLessThanOrEqual((float) time() + 1, $heartbeat);
    }

    #[Test]
    public function no_series_carries_a_customer_hostname(): void
    {
        /*
         * A guest's name is a customer's hostname, and these series travel to
         * whatever scrapes the platform, into every dashboard built on them and
         * into every alert notification they send. `DnsCollector` refuses
         * per-domain labels for the same reason.
         *
         * The alerts' annotations were corrected to stop referencing a
         * `guest_name` label when this collector was written, so that producer
         * and rules agree on what exists.
         */
        Backup::factory()->succeeded()->create(['datastore' => 'vm-backups', 'verified' => null]);

        foreach ((new BackupCollector)->collect() as $metric) {
            foreach ($metric->samples as $sample) {
                $this->assertArrayNotHasKey(
                    'guest_name',
                    $sample->labels,
                    $metric->name.' carries a guest_name label, which is a customer hostname.',
                );
            }
        }
    }

    /** @return list<Metric> */
    private function metrics(): array
    {
        return (new BackupCollector)->collect();
    }

    /** @return list<MetricSample> */
    private function samplesOf(string $name): array
    {
        foreach ($this->metrics() as $metric) {
            if ($metric->name === $name) {
                return $metric->samples;
            }
        }

        $this->fail("No metric named {$name} was produced.");
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function sample(string $name, array $labels): float
    {
        foreach ($this->samplesOf($name) as $sample) {
            $matches = true;

            foreach ($labels as $key => $value) {
                if (($sample->labels[$key] ?? null) !== $value) {
                    $matches = false;
                }
            }

            if ($matches) {
                return (float) $sample->value;
            }
        }

        $this->fail("{$name} has no sample with the labels ".json_encode($labels).'.');
    }

    private function firstSample(string $name): float
    {
        $samples = $this->samplesOf($name);

        $this->assertNotSame([], $samples, "{$name} produced no samples.");

        return (float) $samples[0]->value;
    }
}
