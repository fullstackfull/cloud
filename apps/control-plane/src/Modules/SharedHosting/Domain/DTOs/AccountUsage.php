<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

use Carbon\CarbonImmutable;
use Lynomia\Modules\SharedHosting\Domain\Enums\SslStatus;

/**
 * What one account is using, as the panel reports it.
 *
 * Every measurement is nullable, and that is the whole design. A panel that is
 * mid-restart, one whose quota cache has not been rebuilt, or one answering
 * about an account that was suspended an hour ago returns a summary with the
 * numbers missing — not with zeroes. Modelling "the panel did not say" as 0
 * would be indistinguishable from "the account uses nothing", and the sync
 * would write that over a customer's real figures: quota enforcement based on
 * an empty disk, an overage bill computed from nothing, and a support ticket
 * from a customer whose site stopped accepting uploads.
 *
 * @immutable
 */
final readonly class AccountUsage
{
    /**
     * @param  array<string, mixed>  $raw  The panel's own answer, redacted.
     */
    public function __construct(
        public string $username,
        public ?int $diskUsedMib = null,
        public ?int $diskQuotaMib = null,
        public ?int $bandwidthUsedMib = null,
        public ?int $bandwidthQuotaMib = null,
        public ?int $addonDomains = null,
        public ?int $subdomains = null,
        public ?int $databases = null,
        public ?int $emailAccounts = null,
        public ?bool $suspended = null,
        public ?SslStatus $sslStatus = null,
        public ?CarbonImmutable $sslExpiresAt = null,
        public array $raw = [],
    ) {}

    /**
     * Whether the panel told us anything measurable at all.
     *
     * The sync refuses to write when this is false. An empty answer is not a
     * reading of zero, and the difference is a customer's bill.
     */
    public function hasAnyMeasurement(): bool
    {
        return $this->diskUsedMib !== null
            || $this->diskQuotaMib !== null
            || $this->bandwidthUsedMib !== null
            || $this->bandwidthQuotaMib !== null
            || $this->addonDomains !== null
            || $this->subdomains !== null
            || $this->databases !== null
            || $this->emailAccounts !== null;
    }
}
