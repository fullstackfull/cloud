<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\DTOs;

/**
 * What one renewal run did.
 *
 * `skipped` and `failed` are reported separately on purpose. A skip is a
 * subscription another worker had already renewed — expected, and the mechanism
 * by which two workers converge. A failure is money the platform did not bill
 * for, and an operator needs to be able to tell the two apart at a glance
 * rather than reading a log.
 *
 * @immutable
 */
final readonly class RenewalSweep
{
    /**
     * @param  list<string>  $invoiceIds
     */
    public function __construct(
        public int $considered,
        public int $renewed,
        public int $skipped,
        public int $failed,
        public array $invoiceIds = [],
    ) {}
}
