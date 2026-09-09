<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a machine of a given role is supposed to have on it.
 *
 * A profile is desired state and holds no secrets: it says a Proxmox node runs
 * a monitoring agent and has time synchronisation, not what the monitoring
 * agent's token is. The token arrives at apply time from the deployment
 * controller's environment, which is the same rule the env.j2 template follows.
 *
 * @property string $key
 * @property string $name
 * @property string $intended_role
 * @property bool $is_active
 */
class SoftwareProfile extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<SoftwareComponent, $this>
     */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(SoftwareComponent::class, 'profile_components')
            ->withPivot(['is_required', 'configuration', 'position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * @return HasMany<DesiredState, $this>
     */
    public function desiredStates(): HasMany
    {
        return $this->hasMany(DesiredState::class);
    }
}
