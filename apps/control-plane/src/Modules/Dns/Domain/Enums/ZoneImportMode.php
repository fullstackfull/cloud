<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Enums;

/**
 * What an import does to records the file does not mention.
 *
 * `merge` is the default and touches nothing it was not given: a file with
 * ten records adds or updates ten records and leaves the other forty alone.
 * `replace` makes the zone match the file, which means removing what is not
 * in it — chosen explicitly, shown as REMOVE rows in the preview before it
 * happens, and never inferred from the file's shape.
 */
enum ZoneImportMode: string
{
    case Merge = 'merge';
    case Replace = 'replace';

    public function removesWhatIsAbsent(): bool
    {
        return $this === self::Replace;
    }
}
