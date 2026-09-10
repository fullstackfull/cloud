<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Application\DTOs;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Activity\Domain\Enums\ActivityCategory;
use Lynomia\Modules\Activity\Domain\Enums\ActorType;
use Lynomia\Modules\Shared\Domain\Enums\CustomerOperationState;

/**
 * One thing that happened on a customer's account, as the customer may read it.
 *
 * Every field is either a bounded enum, an identifier the customer already
 * holds, or a name they gave something themselves. There is deliberately no
 * `metadata` bag: the source rows this is projected from carry provider
 * responses, node names, BMC protocols and failure messages, and an
 * open-ended map is how one of those reaches a screen six months from now.
 * Anything a customer needs is promoted to a named field here or it is not
 * published.
 *
 * `messageCode` rather than a sentence. The server decides *what* happened;
 * the portal owns the words, in the customer's language, from the same
 * translation files as every other string. A sentence composed here would be
 * English in an Arabic portal.
 *
 * @immutable
 */
final readonly class ActivityItem
{
    /**
     * @param  string  $id  `source:rowId` — globally unique across the branches
     *                      the feed unions, stable across pages, and the
     *                      cursor's tie-breaker.
     * @param  ?string  $resourceKind  A Wave 3 resource kind (`vps`, `domain`,
     *                                 …) where the row is about a resource the
     *                                 portal has a page for; null for account
     *                                 or billing events that are not.
     * @param  ?string  $reference  A reference the customer can quote to
     *                              support — an invoice number, a ticket
     *                              reference. Never an internal id chosen for
     *                              its uniqueness alone.
     */
    public function __construct(
        public string $id,
        public CarbonImmutable $occurredAt,
        public ActivityCategory $category,
        public string $messageCode,
        public CustomerOperationState $state,
        public ActorType $actorType,
        /**
         * Resolved from `actorUserId` a page at a time. Null for a system or
         * unknown actor, and null before hydration.
         */
        public ?string $actorName = null,
        /**
         * The user row to resolve a name from — an internal join, carried on
         * this DTO and deliberately absent from what the API publishes. A
         * customer's feed needs the name, not the id.
         */
        public ?string $actorUserId = null,
        public ?string $resourceKind = null,
        public ?string $resourceId = null,
        public ?string $resourceIdentity = null,
        public ?string $reference = null,
    ) {}
}
