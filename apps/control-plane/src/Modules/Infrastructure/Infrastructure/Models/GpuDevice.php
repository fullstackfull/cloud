<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Infrastructure\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Infrastructure\Domain\Enums\GpuAllocationState;
use Lynomia\Modules\Infrastructure\Domain\Enums\GpuPassthroughMode;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;

/**
 * One GPU in one managed machine.
 *
 * Has no factory on purpose: a device row is an operator's statement about a
 * card they can see, and a test that needs one registers it through the
 * action, on a machine classified to allow it, the way an operator would.
 */
class GpuDevice extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $attributes = [
        'allocation_state' => 'available',
    ];

    protected function casts(): array
    {
        return [
            'vram_mib' => 'integer',
            'passthrough_mode' => GpuPassthroughMode::class,
            'allocation_state' => GpuAllocationState::class,
        ];
    }

    /**
     * @return BelongsTo<ManagedServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    /**
     * Devices that count as capacity: available, in a mode a guest can hold,
     * on a machine the platform is allowed to configure.
     *
     * The classification check is what stops a card in a do_not_touch chassis
     * from making GPU compute look ready. The machine must have been looked
     * at and permitted before its silicon is capacity.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCapacity(Builder $query): Builder
    {
        return $query
            ->where('allocation_state', GpuAllocationState::Available->value)
            ->where('passthrough_mode', '<>', GpuPassthroughMode::None->value)
            ->whereHas('server', static fn (Builder $server) => $server->whereIn('safety_class', [
                SafetyClass::ConfigurationAllowed->value,
                SafetyClass::ReimageAllowed->value,
            ]));
    }
}
