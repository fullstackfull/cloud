<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProvisioningJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ReservedProviderIdentity;
use Lynomia\Modules\Shared\Infrastructure\Casts\RedactedJsonCast;

/**
 * One unit of provisioning work, and everything needed to survive it failing.
 *
 * Two columns carry more weight than the rest:
 *
 *  - idempotency_key is unique, and is why a double-clicked purchase, a
 *    redelivered webhook and a retried queue message converge on one server
 *    instead of three;
 *  - remote_job_id is the provider's own identifier for the work, written the
 *    moment the provider hands it over. Without it a timeout is unrecoverable:
 *    the platform cannot tell whether the resource exists, and every recovery
 *    is a guess that risks creating a second one.
 *
 * A third, for creates: reserved_provider_id is the identity a create asks
 * the provider for, written before the call rather than learned from the
 * answer — because a create whose answer is lost has no remote_job_id either,
 * and the reserved identity is then the only thing that says where to look.
 * See reserveProviderIdentity().
 *
 * @property string $id
 * @property ?string $service_id
 * @property ?string $requested_by_user_id
 * @property string $idempotency_key
 * @property ProvisioningJobKind $kind
 * @property string $provider
 * @property ProvisioningJobStatus $status
 * @property ?string $remote_job_id
 * @property ?string $remote_task_node
 * @property ?string $remote_task_state
 * @property ?CarbonImmutable $remote_task_polled_at
 * @property int $remote_task_poll_count
 * @property int $attempts
 * @property int $max_attempts
 * @property int $timeout_seconds
 * @property array<string, mixed> $payload
 * @property ?array<string, mixed> $result
 * @property ?FailureClass $failure_class
 * @property ?string $last_error
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property ?CarbonImmutable $next_attempt_at
 * @property ?string $reserved_provider_id
 * @property ?list<string> $reserved_provider_nodes
 * @property ?string $reserved_cluster_id
 * @property ?list<string> $reserved_provider_hostnames
 */
class ProvisioningJob extends Model
{
    /** @use HasFactory<ProvisioningJobFactory> */
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
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'queued',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => RedactedJsonCast::class,
            'result' => RedactedJsonCast::class,
            'kind' => ProvisioningJobKind::class,
            'status' => ProvisioningJobStatus::class,
            'failure_class' => FailureClass::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'timeout_seconds' => 'integer',
            'remote_task_poll_count' => 'integer',
            'remote_task_polled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'reserved_provider_nodes' => 'array',
            'reserved_provider_hostnames' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The attempt log.
     *
     * Not named attempts(): that is the counter column, and a relation of the
     * same name would shadow it — reading $job->attempts would return a
     * collection in some code paths and an integer in others.
     *
     * @return HasMany<ProvisioningAttempt, $this>
     */
    public function attemptRecords(): HasMany
    {
        return $this->hasMany(ProvisioningAttempt::class);
    }

    /**
     * Publish the provider's job id the instant it is known.
     *
     * This is a targeted UPDATE rather than save(), and it is called from
     * inside a handler mid-flight, because the whole value of the column is
     * that it survives what happens next. Waiting for the call to return, or
     * folding it into the settle transaction, would mean the one crash it
     * exists to protect against is the one crash during which it is lost.
     */
    /**
     * @param  ?string  $nodeName  Which node the task is running on. Written with the
     *                             handle, because a handle without a node is a handle
     *                             nothing can ask about — Proxmox answers per node, and
     *                             a later reader would have to guess.
     */
    public function recordRemoteJobId(string $remoteJobId, ?string $nodeName = null): void
    {
        if ($remoteJobId === '' || ($this->remote_job_id === $remoteJobId && $this->remote_task_node === $nodeName)) {
            return;
        }

        $this->remote_job_id = $remoteJobId;

        $columns = ['remote_job_id' => $remoteJobId, 'updated_at' => now()];

        if ($nodeName !== null && $nodeName !== '') {
            $this->remote_task_node = $nodeName;
            $columns['remote_task_node'] = $nodeName;
        }

        static::query()->whereKey($this->getKey())->update($columns);

        // The attributes are now clean: a later save() of unrelated changes
        // must not rewrite columns another worker may since have corrected.
        // The node is synced only when it was set, because an attribute this
        // instance never loaded is not one that can be synced — a nullable
        // column with no default is absent from a freshly created model.
        $this->syncOriginalAttribute('remote_job_id');

        if (array_key_exists('remote_task_node', $columns)) {
            $this->syncOriginalAttribute('remote_task_node');
        }
    }

    /**
     * Write down the identity a create is about to ask its provider for, before
     * it asks, and hand back the identity the row actually holds.
     *
     * F-15. The create's hypervisor id used to be drawn fresh on every attempt
     * and recorded only once the provider had answered, so a create whose
     * answer was lost left nothing behind: an operator's retry drew a new id
     * and built a second machine beside the first. The identity is now held
     * by the job, and every attempt asks for the same one — and looks for what
     * an earlier attempt may have built under it before building anything.
     *
     * One statement, for three reasons:
     *
     *  - **First writer wins.** The id and the cluster are only ever set when
     *    empty, so an attempt that computed a different id (a stale model, a
     *    concurrent claim) is handed the one already held and must use it.
     *    That is why this returns the row's identity rather than echoing its
     *    arguments.
     *  - **The node and the name are recorded with the id**, append-only, in
     *    the same write. There is no instant at which the platform has sent a
     *    create under a name or to a node it has not written down; and what
     *    establishes that a machine found at this id later is this job's own
     *    build is exactly that list of names.
     *  - **The cluster is a condition, not only a value.** An identity is
     *    meaningful in the cluster it was reserved against and nowhere else.
     *    The row is updated only when no cluster is held or the same one is,
     *    and null is returned otherwise — so a create cannot run under an id
     *    reserved on a different cluster however its caller was misled.
     *
     * Raw SQL rather than save(), for the same reason as recordRemoteJobId():
     * it is called mid-flight, and its whole value is that it is committed
     * before the provider is called and survives whatever happens next.
     */
    public function reserveProviderIdentity(
        string $providerId,
        string $clusterId,
        string $nodeName,
        string $hostname,
    ): ?ReservedProviderIdentity {
        $rows = DB::select(
            'update provisioning_jobs set '
            .'reserved_provider_id = coalesce(reserved_provider_id, ?), '
            .'reserved_cluster_id = coalesce(reserved_cluster_id, ?), '
            // Appended only when absent: a list of every node, not a log of
            // every call, so a job retried on one node holds that node once.
            .'reserved_provider_nodes = case '
            ."when coalesce(reserved_provider_nodes, '[]'::jsonb) @> jsonb_build_array(?::text) "
            .'then reserved_provider_nodes '
            ."else coalesce(reserved_provider_nodes, '[]'::jsonb) || jsonb_build_array(?::text) end, "
            .'reserved_provider_hostnames = case '
            ."when coalesce(reserved_provider_hostnames, '[]'::jsonb) @> jsonb_build_array(?::text) "
            .'then reserved_provider_hostnames '
            ."else coalesce(reserved_provider_hostnames, '[]'::jsonb) || jsonb_build_array(?::text) end, "
            .'updated_at = ? '
            .'where id = ? and (reserved_cluster_id is null or reserved_cluster_id = ?) '
            .'returning reserved_provider_id, reserved_cluster_id, reserved_provider_nodes, reserved_provider_hostnames',
            [
                $providerId,
                $clusterId,
                $nodeName,
                $nodeName,
                $hostname,
                $hostname,
                now(),
                $this->getKey(),
                $clusterId,
            ],
        );

        if ($rows === []) {
            return null;
        }

        $row = (array) $rows[0];

        $identity = new ReservedProviderIdentity(
            providerId: (string) $row['reserved_provider_id'],
            clusterId: (string) $row['reserved_cluster_id'],
            nodes: self::listFrom($row['reserved_provider_nodes'] ?? null),
            hostnames: self::listFrom($row['reserved_provider_hostnames'] ?? null),
        );

        // The attributes are now clean, for the reason recordRemoteJobId()
        // gives: a later save() must not write back a stale copy of these.
        $this->reserved_provider_id = $identity->providerId;
        $this->reserved_cluster_id = $identity->clusterId;
        $this->reserved_provider_nodes = $identity->nodes;
        $this->reserved_provider_hostnames = $identity->hostnames;
        $this->syncOriginalAttributes([
            'reserved_provider_id',
            'reserved_cluster_id',
            'reserved_provider_nodes',
            'reserved_provider_hostnames',
        ]);

        return $identity;
    }

    /**
     * The identity this job holds, or null when it has reserved none.
     */
    public function reservedProviderIdentity(): ?ReservedProviderIdentity
    {
        if ($this->reserved_provider_id === null || $this->reserved_provider_id === '') {
            return null;
        }

        return new ReservedProviderIdentity(
            providerId: $this->reserved_provider_id,
            clusterId: (string) $this->reserved_cluster_id,
            nodes: self::listFrom($this->reserved_provider_nodes),
            hostnames: self::listFrom($this->reserved_provider_hostnames),
        );
    }

    /**
     * Whether the engine still has an attempt left to spend on this job.
     */
    public function hasAttemptsRemaining(): bool
    {
        return $this->attempts < $this->max_attempts;
    }

    /**
     * The moment after which the platform stops waiting for this attempt.
     */
    public function deadline(): ?CarbonImmutable
    {
        return $this->started_at?->addSeconds($this->timeout_seconds);
    }

    /**
     * Jobs a worker has been sitting on for longer than they were given.
     *
     * The comparison is done in SQL against each row's own timeout_seconds so
     * that a dedicated server install and a power-on are not held to the same
     * clock, and so that the sweeper never has to load the table to filter it.
     *
     * Against `clock_timestamp()`, not `now()`. Postgres' `now()` is the time
     * the enclosing transaction began, so inside any long transaction — and
     * inside every test, which runs in one — a row whose `started_at` was
     * written from PHP during that transaction could never become stale
     * however long it waited. The selector was unreachable end to end from
     * any test in this repository, which is how two defects in the stale
     * sweep's interaction with F-15's repoint stayed invisible. The wall
     * clock is what "a worker has been sitting on this for too long" means.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStale(Builder $query): Builder
    {
        return $query
            ->where('status', ProvisioningJobStatus::Running->value)
            ->whereNotNull('started_at')
            ->whereRaw("started_at + (timeout_seconds * interval '1 second') < clock_timestamp()");
    }

    /**
     * @return list<string>
     */
    private static function listFrom(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
