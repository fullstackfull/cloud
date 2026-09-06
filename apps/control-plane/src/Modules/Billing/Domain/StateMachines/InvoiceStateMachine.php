<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\StateMachines;

use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Shared\Domain\Contracts\AbstractStateMachine;

/**
 * The invoice lifecycle.
 *
 * An invoice is not a workflow object, it is a document. Once it has been
 * issued its numbers are frozen, and the only thing that still moves is how it
 * was settled. That is why the table narrows sharply after `open`: everything
 * a customer or an operator can still do to a paid invoice is recorded
 * alongside it — a payment, a refund — rather than by rewriting what it says.
 *
 * The transition that is deliberately absent is paid → open. Re-opening a paid
 * invoice would make a document that has been receipted, and possibly filed for
 * tax, collectible again; a payment that has to be undone is a refund, which
 * has its own row, its own money movement and its own audit trail.
 *
 * @extends AbstractStateMachine<InvoiceStatus>
 */
final class InvoiceStateMachine extends AbstractStateMachine
{
    public function subject(): string
    {
        return 'Invoice';
    }

    /**
     * @return array<string, list<InvoiceStatus>>
     */
    public function transitions(): array
    {
        return [
            InvoiceStatus::Draft->value => [
                // Issued to the customer, which is when the number, the dates
                // and the billing snapshot become immutable.
                InvoiceStatus::Open,
                // Discarded before anyone saw it.
                InvoiceStatus::Void,
            ],

            InvoiceStatus::Open->value => [
                InvoiceStatus::Paid,
                InvoiceStatus::Void,
                // Written off after dunning has given up. The document remains,
                // because a written-off invoice is still a document that was
                // issued.
                InvoiceStatus::Uncollectible,
            ],

            InvoiceStatus::Paid->value => [
                InvoiceStatus::Refunded,
            ],

            InvoiceStatus::Uncollectible->value => [
                // Collections and goodwill both happen: a written-off invoice
                // that is eventually paid must be recordable as paid, and one
                // that should never have been issued can still be voided.
                InvoiceStatus::Paid,
                InvoiceStatus::Void,
            ],

            // Terminal. A void invoice is a document that never existed
            // commercially, and a refunded one has already been unwound; both
            // are history, and a correction is a new document.
            InvoiceStatus::Void->value => [],
            InvoiceStatus::Refunded->value => [],
        ];
    }
}
