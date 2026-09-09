<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Enums;

/**
 * What an import would do to one record, shown before it does it.
 *
 * `refused` and `ignored` are the two that are not changes. A refused entry
 * refuses the whole plan: nothing is silently skipped, and the customer
 * corrects the file. An ignored entry is a line the platform does not own
 * and says so — the SOA and the apex NS, which belong to the provider that
 * serves the zone — and it is listed so that nobody wonders where it went.
 */
enum ZoneChangeKind: string
{
    case Add = 'add';
    case Update = 'update';
    case Remove = 'remove';
    case Unchanged = 'unchanged';
    case Refused = 'refused';
    case Ignored = 'ignored';

    public function isChange(): bool
    {
        return match ($this) {
            self::Add, self::Update, self::Remove => true,
            self::Unchanged, self::Refused, self::Ignored => false,
        };
    }
}
