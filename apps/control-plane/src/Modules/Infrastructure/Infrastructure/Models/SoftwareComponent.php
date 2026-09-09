<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One thing that can be installed on a machine, and the reviewed role that
 * installs it.
 *
 * `ansible_role` is the boundary that keeps this from becoming a remote shell.
 * A component names a role that exists in infrastructure/ansible/roles and has
 * been through review; it cannot name a command, and an architecture test
 * fails the build when a component names a role nobody wrote. The alternative
 * — an admin field that becomes an argument to a playbook — is a control panel
 * with an ssh session behind it.
 *
 * @property string $key
 * @property string $name
 * @property ?string $ansible_role
 * @property bool $requires_licence
 * @property list<string> $depends_on
 */
class SoftwareComponent extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_licence' => 'boolean',
            'depends_on' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<SoftwareProfile, $this, ProfileComponent>
     */
    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(SoftwareProfile::class, 'profile_components')
            ->using(ProfileComponent::class)
            ->withPivot(['is_required', 'configuration', 'position'])
            ->withTimestamps();
    }
}
