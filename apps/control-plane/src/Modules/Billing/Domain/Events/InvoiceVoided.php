<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Events;

use Carbon\CarbonImmutable;

/**
 * An invoice was withdrawn before any money arrived on it.
 *
 * Raised by VoidInvoice on the transition to void, once, INSIDE the
 * transaction that voids it, and meant to be heard synchronously: what a
 * listener does is part of the void, commits with it and rolls back with it.
 * That is the opposite of InvoicePaid, and deliberate - a voided plan-change
 * invoice must undo its upgrade before anything else can act on the
 * subscription. A queued listener would run before the commit it depends on;
 * none may be attached. A void of an invoice that is already void raises
 * nothing.
 *
 * Heard by the Subscriptions module: a voided plan-change invoice is an
 * upgrade that will never be paid for, and the subscription is put back on
 * the plan it was paid for rather than billed as the one it never bought.
 *
 * @immutable
 */
final readonly class InvoiceVoided
{
    public function __construct(
        public string $invoiceId,
        public string $customerId,
        public ?string $subscriptionId,
        public CarbonImmutable $voidedAt,
    ) {}
}
