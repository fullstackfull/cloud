<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Application\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Activity\Application\DTOs\AttentionItem;
use Lynomia\Modules\Activity\Domain\Enums\AttentionSeverity;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * What is wrong, or about to be, on one account.
 *
 * Seven questions asked of seven tables, each one bounded and each one asking
 * only about rows that genuinely need a person. This is the list the audit
 * found missing entirely: the seeded account had two open invoices, a ticket
 * waiting for the customer, a subscription ending and an unread notification,
 * and the dashboard said nothing about any of them.
 *
 * Every query here is capped. An account with four hundred overdue invoices
 * has a conversation to have with support, not a dashboard to render, and the
 * cap is what stops the first page of the portal becoming that account's
 * whole billing history.
 *
 * The order is decided here rather than in the browser: severity, then how
 * long it has been true, then the item's own id. Sorting in the client would
 * mean sorting translated text, and an Arabic dashboard would order its
 * warnings differently from an English one.
 */
final readonly class AccountAttention
{
    /** Per class, so one noisy category cannot crowd out the others. */
    private const CAP_PER_CLASS = 5;

    /** The whole list. Beyond this it is a report, not a dashboard. */
    private const CAP_TOTAL = 12;

    /** An invoice inside this window is worth flagging before it is late. */
    private const DUE_SOON_DAYS = 7;

    /** A name inside this window should be renewed now. */
    private const EXPIRING_SOON_DAYS = 30;

    /**
     * @return list<AttentionItem>
     */
    public function for(Customer $customer): array
    {
        $customerId = (string) $customer->getKey();

        $items = [
            ...$this->overdueInvoices($customerId),
            ...$this->invoicesDueSoon($customerId),
            ...$this->resourcesNeedingReview($customerId),
            ...$this->uncertainDomainOperations($customerId),
            ...$this->domainsExpiring($customerId),
            ...$this->backupsNeedingReview($customerId),
            ...$this->supportWaitingForCustomer($customerId),
        ];

        /*
         * Severity, then age, then id. The last key is what makes the order
         * total: two invoices due on the same day must not swap places between
         * two loads of the same dashboard.
         */
        usort($items, function (AttentionItem $a, AttentionItem $b): int {
            return [$a->severity->rank(), $a->occurredAt->getTimestamp(), $a->id]
                <=> [$b->severity->rank(), $b->occurredAt->getTimestamp(), $b->id];
        });

        return array_slice($items, 0, self::CAP_TOTAL);
    }

    /**
     * Money already late.
     *
     * `open` and past due. A draft invoice is not a customer's problem — Wave
     * 2 established that drafts are not on the customer surface at all — and a
     * void or uncollectible one is not something they can pay.
     *
     * @return list<AttentionItem>
     */
    private function overdueInvoices(string $customerId): array
    {
        $rows = DB::table('invoices')
            ->select(['id', 'number', 'due_at'])
            ->where('customer_id', $customerId)
            ->where('status', 'open')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->orderBy('due_at')
            ->limit(self::CAP_PER_CLASS)
            ->get();

        return $rows->map(fn (object $row): AttentionItem => new AttentionItem(
            id: 'invoice_overdue:'.$row->id,
            kind: 'attention.invoice.overdue',
            severity: AttentionSeverity::Critical,
            occurredAt: CarbonImmutable::parse((string) $row->due_at),
            resourceKind: 'invoice',
            resourceId: (string) $row->id,
            resourceIdentity: (string) $row->number,
            reference: (string) $row->number,
        ))->all();
    }

    /**
     * Money due, and not late yet.
     *
     * @return list<AttentionItem>
     */
    private function invoicesDueSoon(string $customerId): array
    {
        $rows = DB::table('invoices')
            ->select(['id', 'number', 'due_at'])
            ->where('customer_id', $customerId)
            ->where('status', 'open')
            ->whereNotNull('due_at')
            ->where('due_at', '>=', now())
            ->where('due_at', '<=', now()->addDays(self::DUE_SOON_DAYS))
            ->orderBy('due_at')
            ->limit(self::CAP_PER_CLASS)
            ->get();

        return $rows->map(fn (object $row): AttentionItem => new AttentionItem(
            id: 'invoice_due_soon:'.$row->id,
            kind: 'attention.invoice.dueSoon',
            severity: AttentionSeverity::Warning,
            occurredAt: CarbonImmutable::parse((string) $row->due_at),
            resourceKind: 'invoice',
            resourceId: (string) $row->id,
            resourceIdentity: (string) $row->number,
            reference: (string) $row->number,
        ))->all();
    }

    /**
     * Work that stopped and is waiting on somebody at Lynomia.
     *
     * The service id is carried rather than the machine's: the overview
     * resolves handles for the whole page at once, the same way the feed does,
     * so a `needs_review` rebuild links the machine rather than the service.
     *
     * @return list<AttentionItem>
     */
    private function resourcesNeedingReview(string $customerId): array
    {
        $rows = DB::table('provisioning_jobs')
            ->select(['id', 'service_id', 'kind', 'finished_at', 'created_at'])
            ->where('customer_id', $customerId)
            ->where('status', 'needs_review')
            ->whereNotNull('service_id')
            ->orderByDesc('created_at')
            ->limit(self::CAP_PER_CLASS)
            ->get();

        return $rows->map(fn (object $row): AttentionItem => new AttentionItem(
            id: 'operation_needs_review:'.$row->id,
            kind: 'attention.operation.needsReview',
            severity: AttentionSeverity::Critical,
            occurredAt: CarbonImmutable::parse((string) ($row->finished_at ?? $row->created_at)),
            resourceKind: null,
            resourceId: (string) $row->service_id,
            /*
             * No reference. An operation's id is a ULID chosen for uniqueness,
             * and the field is for something a customer can quote — an invoice
             * number, a ticket reference. Printing the id beside a hostname on
             * the dashboard is twenty-six characters of noise, and the support
             * link this row carries already names the resource the operation
             * belongs to.
             */
        ))->all();
    }

    /**
     * A registrar operation whose result nobody knows.
     *
     * Critical, and deliberately so: the customer must not repeat it, and the
     * only way they will learn that is if the platform puts it in front of
     * them rather than leaving it on the name's own page.
     *
     * @return list<AttentionItem>
     */
    private function uncertainDomainOperations(string $customerId): array
    {
        $rows = DB::table('domain_operations')
            ->select(['id', 'domain_id', 'name', 'created_at'])
            ->where('customer_id', $customerId)
            ->whereIn('state', ['indeterminate', 'needs_review'])
            ->orderByDesc('created_at')
            ->limit(self::CAP_PER_CLASS)
            ->get();

        return $rows->map(fn (object $row): AttentionItem => new AttentionItem(
            id: 'domain_uncertain:'.$row->id,
            kind: 'attention.domain.uncertain',
            severity: AttentionSeverity::Critical,
            occurredAt: CarbonImmutable::parse((string) $row->created_at),
            resourceKind: 'domain',
            resourceId: (string) $row->domain_id,
            resourceIdentity: (string) $row->name,
        ))->all();
    }

    /**
     * Names running out, and names already in the registry's grace window.
     *
     * Two severities from one query, because the difference matters: a name
     * expiring next week can be renewed at the ordinary price, and one in
     * redemption costs the registry's penalty. A dashboard that treated them
     * alike would let a customer discover the second at the price of the
     * first.
     *
     * Auto-renewing names are still shown when they are inside the window: the
     * renewal has not happened yet, and a customer who sees nothing assumes it
     * has.
     *
     * @return list<AttentionItem>
     */
    private function domainsExpiring(string $customerId): array
    {
        $rows = DB::table('domains')
            ->select(['id', 'name', 'state', 'expires_at'])
            ->where('customer_id', $customerId)
            ->where(function ($query): void {
                $query
                    ->whereIn('state', ['redemption', 'expired', 'grace'])
                    ->orWhere(function ($inner): void {
                        $inner
                            ->where('state', 'active')
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '<=', now()->addDays(self::EXPIRING_SOON_DAYS));
                    });
            })
            ->orderBy('expires_at')
            ->limit(self::CAP_PER_CLASS)
            ->get();

        return $rows->map(function (object $row): AttentionItem {
            $lapsed = in_array((string) $row->state, ['redemption', 'expired', 'grace'], true);

            return new AttentionItem(
                id: 'domain_expiring:'.$row->id,
                kind: $lapsed ? 'attention.domain.lapsed' : 'attention.domain.expiring',
                severity: $lapsed ? AttentionSeverity::Critical : AttentionSeverity::Warning,
                occurredAt: CarbonImmutable::parse((string) ($row->expires_at ?? now())),
                resourceKind: 'domain',
                resourceId: (string) $row->id,
                resourceIdentity: (string) $row->name,
            );
        })->all();
    }

    /**
     * A backup or restore nobody has settled.
     *
     * @return list<AttentionItem>
     */
    private function backupsNeedingReview(string $customerId): array
    {
        $rows = DB::table('backups')
            ->select(['id', 'service_id', 'created_at'])
            ->where('customer_id', $customerId)
            ->where('state', 'needs_review')
            ->whereNotNull('service_id')
            ->orderByDesc('created_at')
            ->limit(self::CAP_PER_CLASS)
            ->get();

        return $rows->map(fn (object $row): AttentionItem => new AttentionItem(
            id: 'backup_needs_review:'.$row->id,
            kind: 'attention.backup.needsReview',
            severity: AttentionSeverity::Critical,
            occurredAt: CarbonImmutable::parse((string) $row->created_at),
            resourceKind: null,
            resourceId: (string) $row->service_id,
        ))->all();
    }

    /**
     * A support request the platform has already answered.
     *
     * The one item on this list where the customer is the blocker, which is
     * why it says so: `waiting_for_customer` is support's own state for "we
     * replied and are waiting", and a customer who never notices is a customer
     * whose problem stays open.
     *
     * @return list<AttentionItem>
     */
    private function supportWaitingForCustomer(string $customerId): array
    {
        $rows = DB::table('support_tickets')
            ->select(['id', 'reference', 'subject', 'last_reply_at', 'created_at'])
            ->where('customer_id', $customerId)
            ->where('status', 'waiting_for_customer')
            ->orderByDesc('last_reply_at')
            ->limit(self::CAP_PER_CLASS)
            ->get();

        return $rows->map(fn (object $row): AttentionItem => new AttentionItem(
            id: 'support_waiting:'.$row->id,
            kind: 'attention.support.waitingForYou',
            severity: AttentionSeverity::Warning,
            occurredAt: CarbonImmutable::parse((string) ($row->last_reply_at ?? $row->created_at)),
            resourceKind: 'support',
            resourceId: (string) $row->id,
            resourceIdentity: (string) $row->subject,
            reference: (string) $row->reference,
        ))->all();
    }
}
