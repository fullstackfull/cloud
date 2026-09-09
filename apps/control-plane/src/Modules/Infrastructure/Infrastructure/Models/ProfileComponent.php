<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The profile ↔ component row. A pivot model only so the row gets a ULID:
 * sync() on a bare pivot writes no primary key, and the table requires one.
 */
class ProfileComponent extends Pivot
{
    use HasUlids;

    protected $table = 'profile_components';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'configuration' => 'array',
        ];
    }
}
