<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Backups\Application\Actions\SettleBackupReview;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\IllegalBackupTransitionException;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Backups waiting for a person, and the one way a person settles them (F-09).
 *
 * A backup row goes to `NeedsReview` when the platform can no longer say what
 * a provider task did, and nothing the platform does takes it back out. For a
 * restore or a verification of a good archive that meant the archive was never
 * offered for restore again — and until the poller measured each operation
 * from when it started, every restore or verification of an archive older
 * than the poll window went there on its first poll.
 *
 * The list names which operation each row interrupted, because that decides
 * what a verdict can mean, and says whether this surface can settle it. The
 * verdict itself is {@see SettleBackupReview}: `completed` or `failed`, with
 * the evidence the operator read and the `review` token the list gave for
 * the row, audited in the same transaction; a verdict whose token no longer
 * names the row's review is refused with `backup.review_changed` (409). Rows it
 * cannot settle — an unaccounted-for backup, a deletion with an unknown
 * outcome, a row from before the platform recorded which operation was
 * interrupted — are listed and refused, not guessed at.
 */
final class BackupReviewController
{
    use ListsAcrossTenants;

    public function index(Request $request): JsonResponse
    {
        $rows = Backup::query()
            ->where('state', BackupState::NeedsReview->value)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows, static fn (Backup $backup): array => [
            'id' => $backup->id,
            'customer_id' => $backup->customer_id,
            'service_id' => $backup->service_id,
            'virtual_machine_id' => $backup->virtual_machine_id,
            'interrupted_operation' => $backup->quarantined_from?->value,
            'resolvable' => $backup->quarantined_from !== null
                && BackupState::afterReview($backup->quarantined_from, completed: true) !== null,
            'failure_reason' => $backup->failure_reason,
            'archive_id' => $backup->archive_id,
            'restore_task_id' => $backup->restore_task_id,
            'restore_started_at' => $backup->restore_started_at?->toIso8601String(),
            'verification_task_id' => $backup->verification_task_id,
            'verification_started_at' => $backup->verification_started_at?->toIso8601String(),
            'in_review_since' => $backup->updated_at?->toIso8601String(),
            // What a verdict on this review sends back as `review`, so it
            // settles this review and no later one.
            'review' => $backup->reviewToken(),
        ]);
    }

    public function resolve(Request $request, string $backup): JsonResponse
    {
        $validated = $request->validate([
            'verdict' => ['required', 'string', 'in:completed,failed'],
            'evidence' => ['required', 'string', 'min:3', 'max:1000'],
            'review' => ['required', 'string', 'size:64'],
        ]);

        $found = Backup::query()->findOrFail($backup);

        if ($found->state !== BackupState::NeedsReview) {
            throw ValidationException::withMessages([
                'verdict' => 'This backup is not waiting for a decision.',
            ]);
        }

        if ($found->quarantined_from === null || BackupState::afterReview($found->quarantined_from, completed: true) === null) {
            throw ValidationException::withMessages([
                'verdict' => 'Only a restore or a verification that the platform lost track of can be settled here.',
            ]);
        }

        $user = $request->user();

        try {
            $settled = app(SettleBackupReview::class)->execute(
                backup: $found,
                completed: $validated['verdict'] === 'completed',
                evidence: $validated['evidence'],
                resolvedBy: $user instanceof User ? sprintf('%s <%s>', $user->name, $user->email) : 'system',
                review: $validated['review'],
            );
        } catch (IllegalBackupTransitionException) {
            // Settled by somebody else between the read above and the lock.
            throw ValidationException::withMessages([
                'verdict' => 'This backup is not waiting for a decision.',
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $settled->id,
                'state' => $settled->state->value,
            ],
        ]);
    }
}
