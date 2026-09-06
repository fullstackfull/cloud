<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Models;

use Database\Factories\OsInstallProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Dedicated\Domain\Enums\InstallerKind;

/**
 * A recipe for an unattended operating-system install.
 *
 * The template is stored rather than generated because the answer file is the
 * thing that decides how a customer's disks are partitioned, and a change to
 * it has to be reviewable, versionable and attributable. `defaults` supplies
 * the values a profile is happy to assume; anything a template needs and the
 * defaults do not cover has to be passed in by the caller, and rendering fails
 * loudly if it is not.
 *
 * `is_active` is honoured at render time, not merely at selection time: a
 * profile withdrawn because it partitions wrongly must stop being used by jobs
 * that were queued before it was withdrawn.
 *
 * @property string $id
 * @property string $slug
 * @property array<string, string> $name
 * @property string $os_family
 * @property string $os_version
 * @property InstallerKind $installer
 * @property string $template
 * @property ?array<string, mixed> $defaults
 * @property bool $is_active
 */
class OsInstallProfile extends Model
{
    /** @use HasFactory<OsInstallProfileFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'installer' => InstallerKind::class,
            'defaults' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PxeBootAuthorisation, $this>
     */
    public function authorisations(): HasMany
    {
        return $this->hasMany(PxeBootAuthorisation::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The human name in one locale, falling back to English and then to the
     * slug, so a half-translated catalogue never renders an empty label.
     */
    public function displayName(?string $locale = null): string
    {
        $locale ??= (string) app()->getLocale();

        return $this->name[$locale] ?? $this->name['en'] ?? $this->slug;
    }
}
