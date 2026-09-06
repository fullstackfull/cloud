<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Enums;

/**
 * The instruction set a template's image is built for.
 *
 * A machine placed on a node of the wrong architecture does not boot, so this
 * is matched exactly and never coerced.
 */
enum CpuArchitecture: string
{
    case X86_64 = 'x86_64';
    case Aarch64 = 'aarch64';
}
