<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Backups\Application\Services\BackupAnnouncements;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupReviewChangedException;
use Lynomia\Modules\Backups\Domain\Exceptions\IllegalBackupTransitionException;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupNotificationKey;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;

/**
 * A person's verdict on a restore or a verification the platform lost track of.
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 *
 * `NeedsReview` is where a row goes when the platform stops being able to
 * say what a provider task did, and nothing the platform does on its own ever
 * takes a row out of it. For a restore or a verification that is a problem the
 * platform made for itself: the archive underneath was good, and a row that
 * stays in review is an archive that is never offered for restore again. Until
 * the poller measured each operation from when it started, any restore or
 * verification of an archive older than `backups.max_poll_hours` went there on
 * its first poll (F-09), so the way back is not a nicety.
 *
 * ---------------------------------------------------------------------------
 * What it will and will not settle
 * ---------------------------------------------------------------------------
 *
 * Exactly what {@see BackupState::afterReview()} answers for: an interrupted
 * restore (completed → `Restored`, failed → `Succeeded`, the archive intact)
 * and an interrupted verification (completed → `Verified`, failed → `Failed`,
 * read back and unreadable). A backup that never reported its archive and a
 * deletion whose outcome is unknown are refused — a verdict does not carry the
 * facts either would need — and so is a row the platform cannot attribute.
 *
 * The verdict is an assertion about the world made by a person, so the
 * evidence they looked at is required, and it lands in the audit trail in the
 * same transaction as the state change. The row is locked for the decision, so
 * two operators settling the same row produce one verdict and one refusal.
 *
 * And the verdict is about the review the person read, not whichever one the
 * row holds when they answer. The two can differ: hours pass between reading
 * the list and deciding, and in them the review can be settled by somebody
 * else, the archive restored again, and that restore lost in turn — the row
 * back in review, for an attempt this person never looked at. The verdict
 * carries the review's token ({@see Backup::reviewToken()}), handed out by
 * the review list, and it is compared with the locked row: a different review
 * is refused ({@see BackupReviewChangedException}), and nothing is written.
 *
 * The customer is told the outcome in the same words the reconciler would
 * have used had it seen the task finish, under the same keys, so an outcome
 * the reconciler already announced is not announced twice.
 */
final readonly class SettleBackupReview
{
    public function __construct(
        private RecordActAtomically $record,
        private BackupAnnouncements $announcements,
    ) {}

    /**
     * @param  string  $review  the token of the review the verdict is about,
     *                          as the review list gave it
     *
     * @throws IllegalBackupTransitionException the row is not in review, or
     *                                          not in a review a verdict can settle
     * @throws BackupReviewChangedException the row is in a review other than the one read
     */
    public function execute(Backup $backup, bool $completed, string $evidence, string $resolvedBy, string $review): Backup
    {
        /** @var array{0: Backup, 1: BackupState} $result */
        $result = $this->record->execute(
            act: static function () use ($backup, $completed, $review): array {
                $locked = Backup::query()->lockForUpdate()->findOrFail($backup->getKey());
                $interrupted = $locked->quarantined_from;
                $current = $locked->reviewToken();

                // Not in review at all is settleReview()'s refusal; in a
                // different review than the one read is this one.
                if ($current !== null && ! hash_equals($current, $review)) {
                    throw BackupReviewChangedException::forBackup((string) $locked->getKey());
                }

                $locked->settleReview($completed, self::attributesFor($interrupted, $completed));

                /** @var BackupState $interrupted settleReview refuses a row without one */
                return [$locked->refresh(), $interrupted];
            },
            describe: static fn (array $result): AuditedAct => new AuditedAct(
                action: $completed ? AuditAction::BackupOperationConfirmed : AuditAction::BackupOperationFailed,
                subject: $result[0],
                customerId: $result[0]->customer_id,
                context: [
                    'operation' => $result[1]->value,
                    'state' => $result[0]->state->value,
                    'evidence' => $evidence,
                    'resolved_by' => $resolvedBy,
                ],
            ),
        );

        [$settled, $interrupted] = $result;

        $this->announce($settled, $interrupted);

        return $settled;
    }

    /**
     * What the reconciler would have written on the same ending.
     *
     * `restored_at` is the moment a person confirmed the restore, which is
     * the nearest thing to its finish the platform has; the evidence says
     * what they read. The reasons are the platform's own sentences, never the
     * operator's words, because `failure_reason` is summarised to the
     * customer and the evidence is for the trail.
     *
     * @return array<string, mixed>
     */
    private static function attributesFor(?BackupState $interrupted, bool $completed): array
    {
        return match (true) {
            $interrupted === BackupState::Restoring && $completed => [
                'restored_at' => now(),
                'failure_reason' => null,
            ],
            $interrupted === BackupState::Restoring => [
                'failure_reason' => 'an operator established that the restore did not complete; the archive is unchanged',
            ],
            $interrupted === BackupState::Verifying && $completed => [
                'verified' => true,
                'verified_at' => now(),
                'failure_reason' => null,
            ],
            $interrupted === BackupState::Verifying => [
                'verified' => false,
                'verified_at' => null,
                'failure_reason' => 'an operator established that the archive could not be read back',
            ],
            default => [],
        };
    }

    private function announce(Backup $settled, BackupState $interrupted): void
    {
        $id = (string) $settled->getKey();

        [$type, $key] = match (true) {
            $interrupted === BackupState::Restoring => [
                $settled->state === BackupState::Restored ? NotificationType::RestoreCompleted : NotificationType::RestoreFailed,
                BackupNotificationKey::restore(
                    $id,
                    $settled->restore_task_id,
                    $settled->state === BackupState::Restored ? 'restored' : 'failed',
                    $settled->restore_started_at?->toIso8601String(),
                ),
            ],
            // A readable archive is not news; an unreadable one is.
            $interrupted === BackupState::Verifying && $settled->verified === false => [
                NotificationType::BackupVerificationFailed,
                BackupNotificationKey::verificationFailed($id),
            ],
            default => [null, null],
        };

        if ($type !== null && $key !== null) {
            $this->announcements->raise($settled, $type, $key);
        }
    }
}
