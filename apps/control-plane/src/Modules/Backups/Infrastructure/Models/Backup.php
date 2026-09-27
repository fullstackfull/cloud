<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BackupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Backups\Domain\Enums\BackupMode;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Enums\BackupTrigger;
use Lynomia\Modules\Backups\Domain\Exceptions\IllegalBackupTransitionException;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * What the platform believes about one backup.
 *
 * The state is moved by {@see self::transitionTo()} and by nothing else, so
 * that "a backup succeeded" is a sentence only a provider task reporting OK
 * can cause to be written. Assigning `$backup->state` directly would let a
 * queue job that merely started report success, which is the single claim this
 * whole module is arranged not to make.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $service_id
 * @property ?string $virtual_machine_id
 * @property ?string $cluster_id
 * @property string $provider
 * @property BackupState $state
 * @property ?BackupState $quarantined_from
 * @property BackupTrigger $trigger
 * @property BackupMode $mode
 * @property string $node_name
 * @property string $datastore
 * @property ?string $provider_task_id
 * @property ?string $archive_id
 * @property ?int $size_bytes
 * @property ?bool $verified
 * @property ?CarbonImmutable $verified_at
 * @property ?string $verification_task_id
 * @property ?CarbonImmutable $verification_requested_at
 * @property ?CarbonImmutable $verification_started_at
 * @property int $verification_attempts
 * @property ?string $restore_task_id
 * @property ?int $retention_days
 * @property ?CarbonImmutable $expires_at
 * @property ?CarbonImmutable $protected_until
 * @property ?CarbonImmutable $deletion_requested_at
 * @property ?string $deletion_requested_by_user_id
 * @property ?string $deletion_reason
 * @property ?string $deletion_task_id
 * @property ?CarbonImmutable $provider_deleted_at
 * @property int $deletion_attempts
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property ?CarbonImmutable $restore_started_at
 * @property ?CarbonImmutable $restored_at
 * @property ?CarbonImmutable $last_polled_at
 * @property int $poll_count
 * @property ?string $failure_reason
 * @property ?string $requested_by_user_id
 * @property ?string $restored_by_user_id
 * @property CarbonImmutable $created_at
 */
class Backup extends Model
{
    /** @use HasFactory<BackupFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * The state a row has before anything happens to it.
     *
     * This duplicates the column default on purpose. A default declared only
     * in the database applies during the INSERT and not to the model object
     * that create() hands back, so a caller that renders its own result reads
     * null for a column the table will happily report a value for one query
     * later. Declared here, every creation path starts in the same state,
     * including the ones written after this comment.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state' => 'requested',
        // The verification sweep reads this straight after a row is created,
        // and a null here would compare as less than the attempt limit by
        // accident rather than by meaning.
        'verification_attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => BackupState::class,
            'quarantined_from' => BackupState::class,
            'trigger' => BackupTrigger::class,
            'mode' => BackupMode::class,
            'size_bytes' => 'integer',
            'verified' => 'boolean',
            'retention_days' => 'integer',
            'poll_count' => 'integer',
            'verified_at' => 'immutable_datetime',
            'verification_requested_at' => 'immutable_datetime',
            'verification_started_at' => 'immutable_datetime',
            'verification_attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'protected_until' => 'immutable_datetime',
            'deletion_requested_at' => 'immutable_datetime',
            'provider_deleted_at' => 'immutable_datetime',
            'deletion_attempts' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'restore_started_at' => 'immutable_datetime',
            'restored_at' => 'immutable_datetime',
            'last_polled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Move the row, or refuse.
     *
     * The transition table lives on the enum; this is the only place that
     * consults it. Two things it makes impossible: reaching `Succeeded` from
     * anywhere but a task that reported OK, and leaving `Failed` at all — a
     * failed backup is not repaired, a new one is taken, and the failure stays
     * in the history where a customer asking "when did this last work?" can
     * see it.
     *
     * @param  array<string, mixed>  $attributes  Written in the same save, so a
     *                                            state and the facts that justify
     *                                            it never land separately.
     *
     * Every route into `NeedsReview` records which state it left, here and
     * not at each caller, so no caller can forget it: that is what tells a
     * restore that may still be writing a machine's disks apart from any
     * other row a person has been asked to look at (F-09).
     *
     * And it is a compare-and-set, not a save over whatever is there. The
     * table's current state is read under a row lock and must still be the
     * one this copy was read in, and so must the attempt this copy read
     * ({@see self::attemptColumns()}); otherwise nothing is written and the
     * refusal says it was a race ({@see IllegalBackupTransitionException::wasRaced()}).
     * Every sweep in this module loads a batch and then makes one provider
     * call per row, and every request reads, checks, asks something else and
     * then writes; checking only the copy in memory let the verification sweep
     * write `verifying` over a restore a customer had started meanwhile, and a
     * stale `delete_requested` move to `deleting` an archive that had been
     * kept and was being restored.
     *
     * The state alone was not enough either. One archive can be `restoring`
     * twice, and a sweep that read the row during the first restore, polled
     * that restore's finished task and compared only the state wrote
     * `Restored` — or `Succeeded` — over a second restore it never polled,
     * releasing the machine while the second restore was writing it (F-09).
     *
     * @throws IllegalBackupTransitionException
     */
    public function transitionTo(BackupState $next, array $attributes = []): void
    {
        $from = $this->state;

        if (! $from->canBecome($next)) {
            throw IllegalBackupTransitionException::between((string) $this->getKey(), $from, $next);
        }

        if ($next === BackupState::NeedsReview) {
            $attributes['quarantined_from'] = $from;
        }

        $this->compareAndSet($from, $next, $attributes);
    }

    /**
     * Write `$next` only if the table still says `$expected`, under a row lock.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws IllegalBackupTransitionException
     */
    private function compareAndSet(BackupState $expected, BackupState $next, array $attributes): void
    {
        DB::transaction(function () use ($expected, $next, $attributes): void {
            $stored = static::query()->whereKey($this->getKey())->lockForUpdate()->toBase()->value('state');

            if ($stored !== $expected->value) {
                throw IllegalBackupTransitionException::movedUnderneath(
                    (string) $this->getKey(),
                    $expected,
                    is_string($stored) ? $stored : null,
                    $next,
                );
            }

            $attempt = $this->attemptColumns($expected);

            if ($attempt !== [] && ! $this->stillOnTheAttemptItRead($attempt)) {
                throw IllegalBackupTransitionException::attemptChanged(
                    (string) $this->getKey(),
                    $expected,
                    $attempt,
                    $next,
                );
            }

            $this->forceFill([...$attributes, 'state' => $next])->save();
        });
    }

    /**
     * The columns that tell one attempt at `$state` from another.
     *
     * A state a row can enter more than once needs them, because the state
     * alone reads the same for every attempt:
     *
     *  - `restoring` — an archive can be restored again, and each attempt has
     *    its own handle and its own start;
     *  - `verifying` — the transition table allows another verification
     *    after `verified`, again with its own handle and start;
     *  - `delete_requested`, and the `deleting` it leads to — a request can be
     *    called off and a new one made, with its own time;
     *  - `needs_review` — which operation it interrupted, and that
     *    operation's attempt.
     *
     * `requested` and `running` have none: a row is created once, and nothing
     * leads back to either. The resting states (`succeeded`, `verified`,
     * `restored`) have no attempt to name; a move out of one is guarded by
     * the state and by whatever lock its caller reads the row under.
     *
     * @return list<string>
     */
    private function attemptColumns(BackupState $state): array
    {
        return match ($state) {
            BackupState::Restoring => ['restore_task_id', 'restore_started_at'],
            BackupState::Verifying => ['verification_task_id', 'verification_started_at'],
            BackupState::DeleteRequested, BackupState::Deleting => ['deletion_requested_at'],
            BackupState::NeedsReview => ['quarantined_from', ...$this->interruptedAttemptColumns()],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function interruptedAttemptColumns(): array
    {
        $interrupted = BackupState::tryFrom((string) $this->getRawOriginal('quarantined_from'));

        return $interrupted === null || $interrupted === BackupState::NeedsReview
            ? []
            : $this->attemptColumns($interrupted);
    }

    /**
     * Whether the locked row still carries the attempt this copy last read or
     * wrote — its original values, not whatever a caller has since assigned.
     *
     * Compared by the database, so a timestamp reads equal whichever of its
     * spellings each side holds.
     *
     * @param  list<string>  $columns
     */
    private function stillOnTheAttemptItRead(array $columns): bool
    {
        $query = static::query()->whereKey($this->getKey());

        foreach ($columns as $column) {
            $read = $this->getRawOriginal($column);

            if ($read === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, $read);
            }
        }

        return $query->exists();
    }

    /**
     * Leave `NeedsReview` on a person's word, or refuse.
     *
     * The one way out of a state the transition table keeps closed, and only
     * to where {@see BackupState::afterReview()} says the interrupted
     * operation could have ended. The caller is the operator's settling
     * action, which records who said so in the same transaction.
     *
     * It does not weaken the rule on {@see self::transitionTo()} that only a
     * task reporting OK makes a backup `Succeeded`: the one row it returns to
     * `Succeeded` is an interrupted restore's, whose archive a task had
     * already reported before the restore began.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws IllegalBackupTransitionException
     */
    public function settleReview(bool $completed, array $attributes = []): BackupState
    {
        $next = $this->state === BackupState::NeedsReview && $this->quarantined_from !== null
            ? BackupState::afterReview($this->quarantined_from, $completed)
            : null;

        if ($next === null) {
            throw IllegalBackupTransitionException::between(
                (string) $this->getKey(),
                $this->state,
                $completed ? BackupState::Succeeded : BackupState::Failed,
            );
        }

        // The same compare-and-set as every other move: two verdicts on one
        // row are one verdict and one refusal, whoever read it first.
        $this->compareAndSet(BackupState::NeedsReview, $next, [...$attributes, 'quarantined_from' => null]);

        return $next;
    }

    /**
     * Whether a restore could actually be started from this archive.
     *
     * The state is not the whole answer, and treating it as one was the
     * defect. `BackupState::isRestorable()` knows that a row has finished and
     * has not been deleted; it knows nothing about whether the archive can be
     * read, because that verdict lives in a different column.
     *
     * `verified` is three-valued and all three values matter here:
     *
     *  - **true** — the datastore read it back. Restorable, obviously.
     *  - **null** — nobody has checked. Still restorable, and deliberately so:
     *    the product contract on {@see BackupState::isRestorable()} says a
     *    customer facing a lost machine would rather try an unverified backup
     *    than be told no. Refusing here would be inventing a policy nobody set
     *    — and on Proxmox Backup Server, which verifies on its own schedule,
     *    it would refuse most archives for most of their life.
     *  - **false** — the datastore read it and it did not come back. Refused.
     *    This is the one the platform had no answer for: a confirmed-corrupt
     *    archive sat on a row that looked like every other completed backup,
     *    with a Restore button beside it, and restoring it writes an
     *    unreadable image over a machine that is currently working.
     */
    public function isRestorable(): bool
    {
        return $this->state->isRestorable() && $this->verified !== false;
    }

    /**
     * Whether this row still needs the provider asked about it.
     *
     * A verification is waiting on its own task, `verification_task_id`;
     * every other in-flight row is keyed on `provider_task_id`, the backup's
     * own handle, which no later operation writes over.
     */
    public function isAwaitingProvider(): bool
    {
        if (! $this->state->isInFlight()) {
            return false;
        }

        return $this->state === BackupState::Verifying
            ? $this->verification_task_id !== null
            : $this->provider_task_id !== null;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<VirtualMachine, $this>
     */
    public function virtualMachine(): BelongsTo
    {
        return $this->belongsTo(VirtualMachine::class);
    }

    /**
     * @return BelongsTo<ComputeCluster, $this>
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(ComputeCluster::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * Stored archives nobody has read back yet, least recently asked about
     * first.
     *
     * Four conditions, and each one excludes a row that would otherwise be
     * asked about for ever:
     *
     *  - `succeeded`, because an archive that is still being written, already
     *    being verified, or being restored is not one to start a verification
     *    against. This is also what makes the sweep idempotent: the row leaves
     *    the scope the moment it is asked.
     *  - an `archive_id`, because that is what a verification names. A
     *    successful task whose archive the provider never reported is a
     *    reconciliation problem, not a verification one.
     *  - `verified` still null, so an archive that has already been read back
     *    is not read again. Re-verification is a cadence nobody has decided.
     *  - fewer attempts than the limit, so a datastore that refuses is asked a
     *    few times rather than every five minutes for the life of the archive.
     *
     * @param  Builder<Backup>  $query
     * @return Builder<Backup>
     */
    public function scopeAwaitingVerification(Builder $query, int $attemptLimit): Builder
    {
        return $query
            ->where('state', BackupState::Succeeded->value)
            ->whereNotNull('archive_id')
            ->whereNull('verified')
            ->where('verification_attempts', '<', $attemptLimit)
            ->orderByRaw('verification_requested_at nulls first')
            ->orderBy('created_at');
    }

    /**
     * Whole-machine restores of one machine that nobody has seen end.
     *
     * A row in `Restoring`, and a row that went to review from `Restoring`:
     * the platform stopped watching that one, but the provider may not have
     * stopped writing, and until a person settles it a second restore over
     * the same disks is the one outcome nobody could reason about afterwards.
     * Reading `restoring` alone is what let a restore of any archive older
     * than the poll window release the machine on its first poll (F-09).
     *
     * @param  Builder<Backup>  $query
     * @return Builder<Backup>
     */
    public function scopeRestoreUnsettledOn(Builder $query, string $virtualMachineId): Builder
    {
        return $query
            ->where('virtual_machine_id', $virtualMachineId)
            ->where(static function (Builder $query): void {
                $query->where('state', BackupState::Restoring->value)
                    ->orWhere(static function (Builder $query): void {
                        $query->where('state', BackupState::NeedsReview->value)
                            ->where('quarantined_from', BackupState::Restoring->value);
                    });
            });
    }

    /**
     * Rows the platform is still waiting on, least recently asked about first.
     *
     * @param  Builder<Backup>  $query
     * @return Builder<Backup>
     */
    public function scopeAwaitingProvider(Builder $query): Builder
    {
        return $query
            ->whereIn('state', [
                BackupState::Requested->value,
                BackupState::Running->value,
                BackupState::Verifying->value,
                BackupState::Restoring->value,
            ])
            // The same rule as isAwaitingProvider(): a verification by its
            // own handle, everything else by the backup's.
            ->where(static function (Builder $query): void {
                $query->where(static function (Builder $query): void {
                    $query->where('state', BackupState::Verifying->value)->whereNotNull('verification_task_id');
                })->orWhere(static function (Builder $query): void {
                    $query->where('state', '!=', BackupState::Verifying->value)->whereNotNull('provider_task_id');
                });
            })
            ->orderByRaw('last_polled_at nulls first')
            ->orderBy('created_at');
    }
}
