<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Models;

use Database\Factories\RackFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;

/**
 * One cabinet in a datacenter.
 *
 * It is a unit of correlated failure smaller than the building: one pair of
 * PDUs, one pair of top-of-rack switches. Recording which rack a machine is in
 * is what turns "four customers went down at once" from a mystery into a
 * cabinet number, and what lets an engineer be sent to the right aisle.
 *
 * @property string $id
 * @property string $datacenter_id
 * @property string $name
 * @property ?string $row
 * @property int $units
 */
class Rack extends Model
{
    /** @use HasFactory<RackFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Datacenter, $this>
     */
    public function datacenter(): BelongsTo
    {
        return $this->belongsTo(Datacenter::class);
    }

    /**
     * @return HasMany<DedicatedServer, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(DedicatedServer::class);
    }

    /**
     * Rack units not occupied by a machine this platform knows about.
     *
     * Deliberately not called "free": a rack also holds switches, PDUs and
     * patch panels that have no row here, so this is an upper bound on what
     * can be installed and never an instruction to install it.
     */
    public function unoccupiedUnits(): int
    {
        $occupied = (int) $this->servers()->sum('height_units');

        return max(0, $this->units - $occupied);
    }
}
