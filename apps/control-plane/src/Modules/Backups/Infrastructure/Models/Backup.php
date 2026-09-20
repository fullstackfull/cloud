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
            'trigger' => BackupTrigger::class,
            'mode' => BackupMode::class,
            'size_bytes' => 'integer',
            'verified' => 'boolean',
            'retention_days' => 'integer',
            'poll_count' => 'integer',
            'verified_at' => 'immutable_datetime',
            'verification_requested_at' => 'immutable_datetime',
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
     * @throws IllegalBackupTransitionException
     */
    public function transitionTo(BackupState $next, array $attributes = []): void
    {
        if (! $this->state->canBecome($next)) {
            throw IllegalBackupTransitionException::between((string) $this->getKey(), $this->state, $next);
        }

        $this->forceFill([...$attributes, 'state' => $next])->save();
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
     */
    public function isAwaitingProvider(): bool
    {
        return $this->state->isInFlight() && $this->provider_task_id !== null;
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
            ->whereNotNull('provider_task_id')
            ->orderByRaw('last_polled_at nulls first')
            ->orderBy('created_at');
    }
}
