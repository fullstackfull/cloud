<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\Enums;

/**
 * A physical thing a product needs to exist in the estate before any provider
 * answer counts.
 *
 * One case so far. It is an enum rather than a boolean on the product so that
 * the second kind of capacity — a spare chassis for dedicated, a datastore for
 * backups — is added by naming it, not by a second boolean.
 */
enum HardwareRequirement: string
{
    /** At least one GPU device registered on a managed machine and available. */
    case GpuDevice = 'gpu_device';
}
