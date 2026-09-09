<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Infrastructure\Models;

use Database\Factories\VmTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;

/**
 * An installable image.
 *
 * cluster_id is nullable so that a catalogue entry can exist before it has
 * been staged on any particular cluster; a template with no cluster is a
 * commercial offer, one with a cluster is something that can actually be
 * built.
 *
 * The checksum is carried because an image is the one artefact the platform
 * hands a customer that it did not build itself. A template swapped underneath
 * us is a supply-chain compromise on every machine created afterwards.
 *
 * @property string $id
 * @property ?string $cluster_id
 * @property string $slug
 * @property array<string, string> $name
 * @property OsFamily $os_family
 * @property string $os_version
 * @property CpuArchitecture $architecture
 * @property ?string $provider_reference
 * @property ?string $checksum
 * @property ?string $checksum_algorithm
 * @property bool $cloud_init
 * @property bool $guest_agent
 * @property bool $requires_licence
 * @property ?string $licence_note
 * @property bool $is_active
 */
class VmTemplate extends Model
{
    /** @use HasFactory<VmTemplateFactory> */
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
        'architecture' => 'x86_64',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'os_family' => OsFamily::class,
            'architecture' => CpuArchitecture::class,
            'cloud_init' => 'boolean',
            'guest_agent' => 'boolean',
            'requires_licence' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ComputeCluster, $this>
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(ComputeCluster::class, 'cluster_id');
    }

    public function nameFor(string $locale): string
    {
        return $this->name[$locale]
            ?? $this->name[config('app.fallback_locale')]
            ?? (string) (array_values($this->name)[0] ?? '');
    }

    /**
     * Whether a machine built from this image can be configured on first boot.
     *
     * Without it the platform has no way to install the customer's key, and a
     * server nobody can log into is not a server that was delivered.
     */
    public function supportsUnattendedSetup(): bool
    {
        return $this->cloud_init;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInstallable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNotNull('provider_reference');
    }
}
