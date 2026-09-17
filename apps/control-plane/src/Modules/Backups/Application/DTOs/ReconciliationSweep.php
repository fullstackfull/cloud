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
 * `skipped` is the same distinction one step further out: a row the sweep
 * looked at and deliberately did not act on, because acting was not possible
 * rather than because it went wrong. An archive on a datastore that cannot be
 * asked to verify is the case it exists for. Defaulted, so the sweeps with
 * nothing to skip read exactly as they did before.
 *
 * @immutable
 */
final readonly class ReconciliationSweep
{
    public function __construct(
        public int $considered,
        public int $settled,
        public int $failed,
        public int $skipped = 0,
    ) {}
}
