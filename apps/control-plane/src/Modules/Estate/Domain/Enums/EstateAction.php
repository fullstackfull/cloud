<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Domain\Enums;

/**
 * What an operation wants to do to a machine, at the coarseness the safety
 * classification decides on.
 *
 * Three, not thirty. A finer vocabulary would let each new action argue for
 * itself about which class it needs, and the point of the classification is
 * that the argument was had once, with the machine's owner.
 */
enum EstateAction: string
{
    /** Ask the machine questions. Writes nothing, anywhere. */
    case Read = 'read';

    /** Change configuration: packages, files, services, monitoring agents. */
    case Configure = 'configure';

    /** Destroy what is on the disks. Partitions, RAID, an operating system. */
    case Reimage = 'reimage';
}
