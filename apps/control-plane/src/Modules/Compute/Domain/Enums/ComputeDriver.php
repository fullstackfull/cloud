<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Enums;

/**
 * The adapter a cluster is driven through.
 *
 * Persisted in compute_clusters.driver, so the values are a schema contract:
 * renaming one orphans every cluster row that carries it.
 */
enum ComputeDriver: string
{
    case Proxmox = 'proxmox';
    case Fake = 'fake';

    /** Whether this driver reaches real infrastructure. */
    public function isReal(): bool
    {
        return $this !== self::Fake;
    }
}
