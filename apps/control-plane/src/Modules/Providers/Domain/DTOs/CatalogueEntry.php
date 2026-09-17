<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\DTOs;

use Lynomia\Modules\Providers\Domain\Enums\ControlledDriver;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;

/**
 * One thing the platform knows how to talk to.
 *
 * An entry is a statement about Lynomia, not about a vendor. It says what this
 * codebase has an adapter for and what that adapter needs before it can be
 * asked to do anything — so a screen offering to register a provider offers
 * the ones that exist, and a registration naming anything else is refused
 * rather than stored and puzzled over later.
 *
 * `testable` is the honest half. Several adapters here can perform real work
 * and cannot yet be connection-tested, because a tester has to be written
 * against the real endpoint and no such endpoint has been available. Recording
 * that as a property means the control centre can say "this cannot be tested
 * yet" instead of offering a button that fails, and it means the readiness
 * engine can refuse to call such a provider production-ready rather than
 * assuming silence is success.
 */
final readonly class CatalogueEntry
{
    /**
     * @param  string  $driver  The adapter key. Matches the driver column and the tester registry.
     * @param  bool  $needsEndpoint  Whether an address must be supplied to reach it.
     * @param  bool  $needsCredential  Whether it authenticates at all.
     * @param  bool  $needsLicence  Whether using it commercially requires a licence we must hold.
     * @param  bool  $controlled  Whether this exists for rehearsal and must never be registered in production.
     */
    public function __construct(
        public string $driver,
        public ProviderCategory $category,
        public bool $needsEndpoint,
        public bool $needsCredential,
        public bool $needsLicence,
        public string $summary,
        public bool $controlled = false,
    ) {}

    /**
     * Built from a controlled driver, so the entry and the refusal cannot
     * disagree about which drivers are for rehearsal.
     */
    public static function controlled(ControlledDriver $driver): self
    {
        return new self(
            driver: $driver->value,
            category: $driver->category(),
            needsEndpoint: $driver->needsEndpoint(),
            needsCredential: $driver->needsCredential(),
            needsLicence: $driver->needsLicence(),
            summary: $driver->summary(),
            controlled: true,
        );
    }

    /**
     * Does an instance of this run on a machine Lynomia manages?
     *
     * Answered by the category rather than stored, because it is a property of
     * what the thing IS: a hypervisor is ours and a payment gateway is not, and
     * an entry that could disagree with its own category would be a second
     * source of truth for the same fact.
     */
    public function needsServer(): bool
    {
        return $this->category->needsServer();
    }
}
