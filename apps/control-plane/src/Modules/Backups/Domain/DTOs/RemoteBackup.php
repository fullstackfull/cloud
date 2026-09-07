<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\DTOs;

/**
 * One backup as the provider currently holds it.
 *
 * Returned by a listing rather than assembled from the platform's own rows,
 * because the two can disagree and the disagreement is the interesting part: a
 * backup the platform believes in and the datastore has pruned is the case a
 * customer discovers at the worst possible moment.
 */
final readonly class RemoteBackup
{
    public function __construct(
        public string $archiveId,
        public string $datastore,
        public ?int $sizeBytes = null,
        public ?int $createdAt = null,
        /**
         * The provider's own verification verdict, when it keeps one. Null
         * means "never verified", which is not the same as "verified and
         * failed" and must not be rendered as though it were.
         */
        public ?bool $verified = null,
        public ?string $notes = null,
    ) {}
}
