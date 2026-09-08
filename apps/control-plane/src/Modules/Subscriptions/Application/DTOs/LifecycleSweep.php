<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\DTOs;

/**
 * What one lifecycle run did.
 *
 * "Considered" and "acted on" are reported separately because they answer
 * different questions: the first says whether the sweep is finding work, the
 * second whether it is completing it. A run that considers hundreds and
 * advances none is broken in a way that a single number would hide.
 *
 * @immutable
 */
final readonly class LifecycleSweep
{
    public function __construct(
        public int $cancellationsConsidered,
        public int $cancelled,
        public int $dunningConsidered,
        public int $advanced,
        public int $failed,
    ) {}
}
