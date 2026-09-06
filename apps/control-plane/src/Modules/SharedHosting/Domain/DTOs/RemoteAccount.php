<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

/**
 * One account as the panel lists it.
 *
 * This is what reconciliation runs on: accounts the panel holds that the
 * platform has no row for (created by hand during an incident, or left behind
 * by a create whose answer was lost) and rows the platform holds that the
 * panel does not (terminated out of band). Both are billing errors in
 * opposite directions, and neither is visible from inside the platform alone.
 *
 * @immutable
 */
final readonly class RemoteAccount
{
    /**
     * @param  string|null  $primaryDomain  Null when the panel's listing does not carry it.
     *                                      DirectAdmin's user listing is names only, and
     *                                      fetching a domain per account would be one call
     *                                      per customer on a node with several hundred of
     *                                      them. Reconciliation needs existence, not
     *                                      domains, so the absence is recorded rather than
     *                                      paid for.
     * @param  array<string, mixed>  $raw  The panel's own row, redacted.
     */
    public function __construct(
        public string $username,
        public ?string $primaryDomain,
        public ?string $packageName = null,
        public bool $suspended = false,
        public ?int $diskUsedMib = null,
        public ?string $ipAddress = null,
        public array $raw = [],
    ) {}
}
