<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\VirtualMachineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * The platform's record of one machine on a hypervisor.
 *
 * provider_id is nullable for exactly as long as it takes the hypervisor to
 * answer: the row is written first, so that a create whose response is lost
 * still leaves a trace to reconcile against. cluster_id and node_id are
 * nullable for the opposite reason — a cluster can be decommissioned while its
 * history stays readable.
 *
 * The vcpu/memory/disk columns are what the platform believes it built, not
 * what it observes. Reconciliation compares them with the hypervisor and
 * records the difference in drift_details rather than correcting it, because
 * an automated correction here resizes a customer's running machine.
 *
 * @property string $id
 * @property string $service_id
 * @property ?string $cluster_id
 * @property ?string $node_id
 * @property ?string $template_id
 * @property ?string $provider_id
 * @property ?string $storage_name
 * @property string $hostname
 * @property int $vcpu
 * @property int $memory_mib
 * @property int $disk_gib
 * @property PowerState $power_state
 * @property ?string $os_family
 * @property ?string $os_version
 * @property ?CarbonImmutable $last_reconciled_at
 * @property bool $has_drift
 * @property ?array<string, mixed> $drift_details
 */
class VirtualMachine extends Model
{
    /** @use HasFactory<VirtualMachineFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'power_state' => PowerState::class,
            'vcpu' => 'integer',
            'memory_mib' => 'integer',
            'disk_gib' => 'integer',
            'last_reconciled_at' => 'immutable_datetime',
            'has_drift' => 'boolean',
            'drift_details' => 'array',
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
     * @return BelongsTo<ComputeCluster, $this>
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(ComputeCluster::class, 'cluster_id');
    }

    /**
     * @return BelongsTo<ComputeNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(ComputeNode::class, 'node_id');
    }

    /**
     * @return BelongsTo<VmTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(VmTemplate::class, 'template_id');
    }

    /**
     * The capacity this machine holds on its node, which is what has to be
     * given back when it is destroyed.
     */
    public function resources(): VmResources
    {
        return new VmResources($this->vcpu, $this->memory_mib, $this->disk_gib);
    }

    /**
     * Whether the hypervisor has confirmed this machine exists.
     *
     * A row without a provider id is either mid-create or the residue of a
     * create whose response never arrived; both need reconciliation before
     * anything acts on them.
     */
    public function existsRemotely(): bool
    {
        return $this->provider_id !== null && $this->provider_id !== '';
    }
}
