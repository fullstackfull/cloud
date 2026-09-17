<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Backups\Application\DTOs\ReconciliationSweep;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Enums\VerificationAttempt;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Throwable;

/**
 * Ask the datastore to read back every archive nobody has checked yet.
 *
 * ---------------------------------------------------------------------------
 * The half of verification that did not exist
 * ---------------------------------------------------------------------------
 *
 * `startVerification` was on the provider contract and on both drivers.
 * `Verifying` was a state, with `Succeeded → Verifying → Verified` in the
 * transition table, and {@see ReconcileBackup} already knew how to settle a
 * verification task into a verdict. Nothing anywhere put a row into
 * `Verifying`, so the whole apparatus ran on no input and `verified` was null
 * for every backup the platform had ever taken.
 *
 * `docs/backups.md` is unambiguous about why that matters: verification "is
 * what turns 'the job reported success' into 'the data is readable'", and
 * "deduplicating backup storage means a single corrupted chunk can affect many
 * backups at once, and only verification finds it before a restore does". It
 * is the narrowest policy the repository's own evidence supports: an archive
 * that stored successfully is an archive to read back.
 *
 * ---------------------------------------------------------------------------
 * Why a sweep, and not a call at the moment of success
 * ---------------------------------------------------------------------------
 *
 * Starting the verification inside the success transition would be one call at
 * exactly the right moment and no way to recover from it. A datastore that
 * refused, or was briefly unreachable, would leave that archive unverified for
 * the rest of its life with nothing to notice — which is the shape of the
 * defect being fixed, one layer further in.
 *
 * So it is a sweep on the same schedule and the same shape as
 * {@see ReconcileRunningBackups}: least recently asked about first, one
 * provider failure counted rather than fatal, and a bounded number of attempts
 * before an archive is left for a person.
 *
 * ---------------------------------------------------------------------------
 * What makes running this every five minutes for ever safe
 * ---------------------------------------------------------------------------
 *
 * A row that has been asked is no longer in scope: `Succeeded → Verifying`
 * takes it out, and the poller settles it into `Verified` or `Failed` from
 * there. A row whose provider refused stays `Succeeded` with its attempt
 * counter raised, so a broken datastore is asked a few times and then left
 * alone rather than several hundred times an hour.
 *
 * Re-verification of an already-verified archive is deliberately NOT done
 * here. `Verified → Verifying` is a legal transition and a scheduled
 * re-read of old archives is a real operational practice, but it is a policy
 * about how often and at what cost, and nothing in this repository states one.
 * Inventing a cadence would be inventing a product decision.
 *
 * ---------------------------------------------------------------------------
 * A provider that cannot be asked is not a provider that failed
 * ---------------------------------------------------------------------------
 *
 * Proxmox Backup Server verifies on its own schedule, and the hypervisor API
 * exposes no endpoint that starts a verification. Its adapter says so —
 * `supportsVerification()` answers false, `startVerification()` refuses — so
 * this sweep asks before it calls.
 *
 * The version of this that did not ask was wrong in a way worth naming,
 * because everything about it looked fine: the attempt limit bounded it, so
 * each archive was refused three times rather than for ever. But those three
 * refusals raised the attempt counter and wrote the refusal into
 * `failure_reason`, which is the column a person reads as the verdict on the
 * archive — so a perfectly good backup on a perfectly healthy datastore ended
 * up carrying a sentence about verification failing. On the one adapter that
 * can actually run in production, that was every backup the platform would
 * ever take.
 *
 * So an archive on such a provider is left alone here, with nothing counted
 * against it, and its verdict arrives the way that provider gives one:
 * {@see ReconcileBackupInventory} reads it off the datastore listing.
 */
final readonly class VerifyStoredArchives
{
    public function __construct(
        private BackupProviderFactory $providers,
        private SecretRedactor $redactor,
    ) {}

    public function execute(int $limit = 50): ReconciliationSweep
    {
        $started = 0;
        $failed = 0;
        $unaskable = 0;

        $unverified = Backup::query()
            ->awaitingVerification($this->attemptLimit())
            ->limit($limit)
            ->get();

        foreach ($unverified as $backup) {
            try {
                match ($this->start($backup)) {
                    VerificationAttempt::Started => $started++,
                    VerificationAttempt::Refused => $failed++,
                    VerificationAttempt::NotAskable => $unaskable++,
                };
            } catch (Throwable $e) {
                $failed++;

                Log::error('A stored archive could not be sent for verification.', [
                    'backup_id' => $backup->getKey(),
                    'cluster_id' => $backup->cluster_id,
                    'exception' => $e::class,
                    // The message is not logged here. A provider message is
                    // redacted below where it is kept; one that escaped to
                    // this branch would be one nothing had redacted.
                ]);
            }
        }

        return new ReconciliationSweep(
            considered: $unverified->count(),
            settled: $started,
            failed: $failed,
            /*
             * Its own number, and not folded into either of the others. These
             * rows were not settled and nothing about them failed: the
             * platform looked, found a provider it cannot ask, and left them
             * for the inventory sweep. Counting them as failures would put a
             * standing non-zero failure count on a healthy platform.
             */
            skipped: $unaskable,
        );
    }

    private function start(Backup $backup): VerificationAttempt
    {
        $cluster = $backup->cluster()->first();

        if ($cluster === null) {
            // The cluster row is gone, so there is no datastore to ask and no
            // adapter to ask it with. The archive may still be sitting on a
            // datastore nobody is looking at, which is a person's problem.
            $backup->transitionTo(BackupState::NeedsReview, [
                'failure_reason' => 'the cluster this backup was taken on no longer exists, so the archive cannot be read back',
            ]);

            return VerificationAttempt::Refused;
        }

        $provider = $this->providers->for($cluster);

        /*
         * Asked before anything is written, so a provider that cannot start a
         * verification costs this archive nothing: no attempt counted, no
         * failure reason, no row touched at all.
         */
        if (! $provider->supportsVerification()) {
            return VerificationAttempt::NotAskable;
        }

        /*
         * Counted before the call, not after it.
         *
         * A process that dies between the request and the response must leave
         * evidence that the attempt happened; a counter raised afterwards
         * would let a provider that kills the worker be asked for ever.
         */
        $backup->forceFill([
            'verification_requested_at' => now(),
            'verification_attempts' => $backup->verification_attempts + 1,
        ])->save();

        try {
            $operation = $provider->startVerification(
                $backup->node_name,
                (string) ($backup->datastore ?? $this->providers->datastoreFor($cluster)),
                (string) $backup->archive_id,
            );
        } catch (BackupProviderException $e) {
            /*
             * Left where it is, whichever kind of failure this was.
             *
             * An indeterminate start is the interesting one: the datastore may
             * be verifying right now under a task handle this platform never
             * received. Moving the row to `Verifying` without a task id would
             * strand it in a state the poller cannot settle, and marking it
             * failed would report a readable archive as unreadable. It stays
             * `Succeeded` — which is true — with the attempt recorded, and the
             * attempt limit is what stops this repeating for ever.
             */
            $backup->forceFill([
                'failure_reason' => $this->redactor->redactString($e->getMessage()),
            ])->save();

            return VerificationAttempt::Refused;
        }

        $backup->transitionTo(BackupState::Verifying, [
            /*
             * Both columns, and they are not the same thing.
             *
             * `provider_task_id` is what the poller reads — one column for
             * "whatever task this row is currently waiting on", which is what
             * lets one reconciler settle backups, verifications and restores
             * alike. `verification_task_id` is the durable record of which
             * task was the verification, which survives the row moving on to
             * its next operation.
             */
            'provider_task_id' => $operation->taskId,
            'verification_task_id' => $operation->taskId,
            'last_polled_at' => null,
            'poll_count' => 0,
            // Cleared: the row is in flight again, and a reason left over from
            // a previous refusal would read as the verdict on this one.
            'failure_reason' => null,
        ]);

        return VerificationAttempt::Started;
    }

    private function attemptLimit(): int
    {
        return max(1, (int) config('backups.verification_attempts', 3));
    }
}
