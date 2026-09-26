<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the F-09 migration writes on rows that were already in flight or in
 * review when it ran.
 *
 * The migration is run down and up again inside the test's transaction, with
 * rows shaped the way the previous code left them.
 */
final class RowsAlreadyInFlightKeepTheirOwnClockTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION = 'database/migrations/2026_04_29_000041_record_when_each_backup_operation_started_and_which_one_a_review_interrupted.php';

    #[Test]
    public function the_backfill_gives_each_row_the_clock_and_the_interruption_it_can_prove(): void
    {
        /** @var Migration $migration */
        $migration = require base_path(self::MIGRATION);
        $migration->down();

        $requested = now()->subHours(2)->startOfSecond();

        $verifying = $this->row(BackupState::Verifying, ['verification_requested_at' => $requested]);
        $interruptedRestore = $this->row(BackupState::NeedsReview, ['restore_started_at' => now()->subHour()]);
        $restoredThenLost = $this->row(BackupState::NeedsReview, [
            'restore_started_at' => now()->subDays(2),
            'restored_at' => now()->subDays(2)->addHour(),
        ]);
        $lostBackup = $this->row(BackupState::NeedsReview, []);

        $migration->up();

        $this->assertTrue($requested->equalTo(Backup::query()->findOrFail($verifying)->verification_started_at));
        $this->assertSame(BackupState::Restoring, Backup::query()->findOrFail($interruptedRestore)->quarantined_from);
        $this->assertNull(Backup::query()->findOrFail($restoredThenLost)->quarantined_from, 'That restore was seen to finish.');
        $this->assertNull(Backup::query()->findOrFail($lostBackup)->quarantined_from, 'Nothing proves which operation this was.');
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function row(BackupState $state, array $columns): string
    {
        $backup = Backup::factory()->takenHoursAgo(72)->create(['provider_task_id' => 'UPID:fake:'.$state->value.'-'.count($columns).'-'.random_int(1, PHP_INT_MAX)]);

        DB::table('backups')->where('id', $backup->id)->update([...$columns, 'state' => $state->value]);

        return $backup->id;
    }
}
