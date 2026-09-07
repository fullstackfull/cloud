<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\DTOs;

/**
 * What one reconciliation run did.
 *
 * `settled` counts rows whose state actually changed. A backup the provider is
 * still working on is neither settled nor failed — it is simply still running,
 * and a sweep that counted it as work done would report progress it did not
 * make.
 *
 * @immutable
 */
final readonly class ReconciliationSweep
{
    public function __construct(
        public int $considered,
        public int $settled,
        public int $failed,
    ) {}
}
