<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Application\DTOs;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Activity\Domain\Enums\AttentionSeverity;

/**
 * One thing on the account that needs a person.
 *
 * BD-4 is decided: the dashboard is attention first. So an attention item has
 * to answer five questions, and every field here is one of them — what needs
 * attention (`kind`), how urgent (`severity`), what it affects (`resource`),
 * what to do (the portal's copy, keyed by `kind`), and where to click
 * (`resource`, resolved through the same routing map as everything else).
 *
 * Two rules about what is *not* an attention item:
 *
 *  - Healthy states never appear. A running server, a paid invoice and a
 *    resolved ticket are all facts about a working account; putting them in a
 *    list called "needs your attention" trains a customer to ignore the list.
 *  - Nothing is invented. Every item below is a row that exists — an unpaid
 *    invoice, a name in its redemption window, a job waiting on review — and
 *    where the platform has no such row, the dashboard says nothing rather
 *    than guessing.
 *
 * `occurredAt` is what the item is dated by: a due date for an invoice, an
 * expiry for a name, the moment work stopped for an operation. It is the
 * second sort key after severity, so the oldest problem of equal weight is
 * highest.
 *
 * @immutable
 */
final readonly class AttentionItem
{
    /**
     * @param  string  $kind  A bounded code the portal renders in the reader's
     *                        language — never a composed sentence.
     * @param  ?string  $reference  Something quotable to support: an invoice
     *                              number, a ticket reference.
     */
    public function __construct(
        public string $id,
        public string $kind,
        public AttentionSeverity $severity,
        public CarbonImmutable $occurredAt,
        public ?string $resourceKind = null,
        public ?string $resourceId = null,
        public ?string $resourceIdentity = null,
        public ?string $reference = null,
    ) {}
}
