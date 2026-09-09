<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Lynomia\Modules\Dns\Domain\Enums\ZoneImportMode;
use Lynomia\Modules\Dns\Domain\Enums\ZoneImportOutcome;

/**
 * An attempt to apply a zone import, as a fact for the metric and the
 * operator, never as something a screen edits.
 */
class DnsZoneImport extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mode' => ZoneImportMode::class,
            'outcome' => ZoneImportOutcome::class,
            'added' => 'integer',
            'updated' => 'integer',
            'removed' => 'integer',
            'unchanged' => 'integer',
            'refused' => 'integer',
            'ignored' => 'integer',
        ];
    }
}
