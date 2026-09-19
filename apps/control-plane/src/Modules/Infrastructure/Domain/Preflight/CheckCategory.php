<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Preflight;

/**
 * Which layer a check belongs to.
 *
 * Used for grouping output and, more usefully, for reading a report at a
 * glance: eight failures in Mapping is an afternoon of configuration, one
 * failure in Credential is a five-minute fix, and one in Hardware is a
 * purchase order.
 */
enum CheckCategory: string
{
    case Configuration = 'configuration';
    case Credential = 'credential';
    case Network = 'network';
    case Identity = 'identity';
    case Capability = 'capability';
    case Mapping = 'mapping';
    case Licence = 'licence';
    case Hardware = 'hardware';
    case Backup = 'backup';
    case Monitoring = 'monitoring';
    case Readiness = 'readiness';
}
