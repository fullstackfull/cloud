<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one machine is supposed to look like.
 *
 * A profile plus this machine's departures from it. Overrides exist because
 * the alternative — a profile per machine — stops the profile meaning anything.
 * They are validated against each component's declared schema rather than
 * accepted as free-form JSON, so an override cannot smuggle in a value the
 * role was never written to receive.
 */
class DesiredState extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'overrides' => 'array',
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
     * @return BelongsTo<SoftwareProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(SoftwareProfile::class, 'software_profile_id');
    }
}
