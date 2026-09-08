<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\ValueObjects;

/**
 * What a plan entitles a service to, where backups are concerned.
 *
 * Read from the plan's `resources` document, which is where every other
 * entitlement already lives — vCPU, memory, disk, address counts. There is
 * deliberately no second configuration system: a backup allowance is a thing
 * the customer bought, and things the customer bought live in the catalogue.
 *
 * Every field has a default, and the defaults are the reason a plan written
 * before this existed does not become a plan with no backups. A plan that says
 * nothing about backups gets the platform's own policy, which is
 * `config('backups.retention_days')` and no ceiling on how many are kept —
 * exactly the behaviour those plans had yesterday.
 *
 * @immutable
 */
final readonly class BackupPolicy
{
    public function __construct(
        /** How long a backup is kept before the sweep may remove it. */
        public int $retentionDays,
        /**
         * The most backups this service keeps at once, oldest removed first.
         * Null means no ceiling — the retention window is the only limit.
         */
        public ?int $maxRetained,
        /** How many backups the customer may take themselves per month. */
        public ?int $manualAllowance,
        /** How many the scheduler may take per month. Null means unmetered. */
        public ?int $scheduledAllowance,
        /**
         * Whether the customer may delete a backup before it expires.
         *
         * Off for a plan that sells retention as a compliance guarantee: an
         * account whose backups are its evidence must not be able to destroy
         * that evidence from a web page.
         */
        public bool $customerMayDelete,
    ) {}

    /**
     * @param  array<string, mixed>  $resources  a plan's `resources` document
     */
    public static function fromPlanResources(array $resources): self
    {
        return new self(
            retentionDays: self::positiveInt($resources, 'backup_retention_days')
                ?? max(1, (int) config('backups.retention_days', 7)),
            maxRetained: self::positiveInt($resources, 'backup_max_retained'),
            manualAllowance: self::positiveInt($resources, 'backup_manual_allowance'),
            scheduledAllowance: self::positiveInt($resources, 'backup_scheduled_allowance'),
            /*
             * Defaults to true. A customer who can take a backup and cannot
             * remove it is a customer whose datastore usage only ever grows,
             * and the plans that genuinely need the opposite say so.
             */
            customerMayDelete: ! array_key_exists('backup_customer_may_delete', $resources)
                || (bool) $resources['backup_customer_may_delete'],
        );
    }

    /** The platform's own policy, for a service with no plan behind it. */
    public static function default(): self
    {
        return self::fromPlanResources([]);
    }

    /**
     * @param  array<string, mixed>  $resources
     */
    private static function positiveInt(array $resources, string $key): ?int
    {
        $value = $resources[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $number = (int) $value;

        // Zero is not "no limit", it is a plan somebody misconfigured. Treated
        // as absent so a typo cannot silently forbid every backup.
        return $number > 0 ? $number : null;
    }
}
