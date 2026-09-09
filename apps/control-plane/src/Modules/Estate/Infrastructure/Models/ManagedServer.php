<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;
use Lynomia\Modules\Estate\Domain\Enums\ConnectionState;
use Lynomia\Modules\Estate\Domain\Enums\EstateAction;
use Lynomia\Modules\Estate\Domain\Enums\EstateEnvironment;
use Lynomia\Modules\Estate\Domain\Enums\SafetyClass;
use Lynomia\Modules\Estate\Domain\Enums\ServerState;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;

/**
 * A machine, seen from the operator's side rather than the customer's.
 *
 * compute_nodes says how much capacity a hypervisor has. hosting_nodes says
 * how many accounts a web server is carrying. Neither says whether anybody has
 * agreed we may write to the thing, which credential opens it, or what was
 * last read off it — and those questions have the same answer shape for every
 * machine we own, including one that has arrived and has no role yet.
 *
 * @property string $id
 * @property string $name
 * @property EstateEnvironment $environment
 * @property ServerState $state
 * @property SafetyClass $safety_class
 * @property bool $allow_reimage
 * @property ConnectionState $connection_state
 */
class ManagedServer extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => EstateEnvironment::class,
            'state' => ServerState::class,
            'safety_class' => SafetyClass::class,
            'connection_state' => ConnectionState::class,
            'allow_reimage' => 'boolean',
            'rack_unit' => 'integer',
            'height_units' => 'integer',
            'management_port' => 'integer',
            'bmc_port' => 'integer',
            'safety_changed_at' => 'immutable_datetime',
            'last_connection_test_at' => 'immutable_datetime',
            'last_discovery_at' => 'immutable_datetime',
            'last_deployment_at' => 'immutable_datetime',
            'last_verification_at' => 'immutable_datetime',
        ];
    }

    /**
     * May this machine be acted on in this way, right now?
     *
     * The whole safety model reduces to this method, and everything that could
     * change a machine is expected to ask it rather than reason about the
     * class itself. Reimage needs both halves: the classification, which is an
     * operator's standing decision about the machine, and allow_reimage, which
     * is their decision about this particular piece of scheduled work.
     *
     * The database enforces that the second cannot be true without the first,
     * so this cannot be tricked by a row written around the application.
     */
    public function permits(EstateAction $action): bool
    {
        if (! $this->safety_class->permits($action)) {
            return false;
        }

        if ($action === EstateAction::Reimage) {
            return $this->allow_reimage;
        }

        return true;
    }

    /** Is anything at all permitted against this machine? */
    public function isTouchable(): bool
    {
        return $this->safety_class !== SafetyClass::DoNotTouch;
    }

    /**
     * The machine's role-specific row, if it has been given one.
     *
     * Deliberately not a polymorphic relation: there are three kinds and they
     * are genuinely different tables with different meanings, and a morph would
     * buy tidiness at the cost of every query having to know which it got.
     *
     * @return BelongsTo<ComputeNode, $this>
     */
    public function computeNode(): BelongsTo
    {
        return $this->belongsTo(ComputeNode::class);
    }

    /**
     * @return BelongsTo<HostingNode, $this>
     */
    public function hostingNode(): BelongsTo
    {
        return $this->belongsTo(HostingNode::class);
    }

    /**
     * @return BelongsTo<Datacenter, $this>
     */
    public function datacenter(): BelongsTo
    {
        return $this->belongsTo(Datacenter::class);
    }

    /**
     * @return BelongsTo<Rack, $this>
     */
    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function safetyChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'safety_changed_by');
    }

    /**
     * @return HasMany<ServerFact, $this>
     */
    public function facts(): HasMany
    {
        return $this->hasMany(ServerFact::class);
    }

    /**
     * @return HasOne<DesiredState, $this>
     */
    public function desiredState(): HasOne
    {
        return $this->hasOne(DesiredState::class);
    }

    /**
     * @return HasMany<DeploymentPlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(DeploymentPlan::class);
    }

    /**
     * @return HasMany<DeploymentJob, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(DeploymentJob::class);
    }

    /**
     * @return HasMany<ConnectionTest, $this>
     */
    public function connectionTests(): HasMany
    {
        return $this->hasMany(ConnectionTest::class);
    }

    /**
     * @return HasMany<ProviderInstance, $this>
     */
    public function providerInstances(): HasMany
    {
        return $this->hasMany(ProviderInstance::class);
    }
}
