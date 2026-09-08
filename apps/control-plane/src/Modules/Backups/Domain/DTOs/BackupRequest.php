<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\DTOs;

use Lynomia\Modules\Backups\Domain\Enums\BackupMode;

/**
 * What the platform is asking a provider to back up, and where to put it.
 *
 * The datastore is required rather than defaulted. A provider will happily
 * write a backup to whichever storage it considers default, and on a
 * hypervisor that is frequently the same disks the machine is running on —
 * which is a copy, not a backup, and is lost by exactly the failure a backup
 * exists for.
 */
final readonly class BackupRequest
{
    public function __construct(
        public string $nodeName,
        public string $providerId,
        public string $datastore,
        public BackupMode $mode = BackupMode::Snapshot,
        public ?string $notes = null,
        /**
         * How long the provider should keep this one, when the provider
         * supports being told. Null means "whatever the datastore's own prune
         * policy says", which is the normal case for a scheduled series.
         */
        public ?int $retentionDays = null,
    ) {}
}
