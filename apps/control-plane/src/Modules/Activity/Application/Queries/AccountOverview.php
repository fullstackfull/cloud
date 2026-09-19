<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Application\Queries;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Activity\Application\DTOs\ActivityItem;
use Lynomia\Modules\Activity\Application\DTOs\AttentionItem;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Infrastructure\Queries\ServiceIdentities;

/**
 * The first page of the portal, in one read.
 *
 * AR-6. The dashboard was two account cards and a country-change form: no
 * service counts, no invoices due, no renewals, no tickets, no unread count,
 * no quick actions — and it rendered `null` while the user loaded, with no
 * error state at all.
 *
 * The rule this class exists to enforce is that the *server* answers the
 * dashboard's questions. The alternative the audit itself floated — "four
 * existing list calls" — would have the browser fetch services, invoices,
 * subscriptions and tickets and decide between them what deserves attention,
 * which means every client reimplementing the priority rules, and a phone
 * paying for four list payloads to draw six numbers.
 *
 * Bounded by construction:
 *
 *  - the service summary is one grouped count, not a page of services;
 *  - money due is one grouped sum **per currency**, never a total;
 *  - renewals and activity are short, capped lists;
 *  - the attention list is capped per class and overall;
 *  - resource handles for the whole response resolve in one pass.
 *
 * ---------------------------------------------------------------------------
 * Money
 * ---------------------------------------------------------------------------
 *
 * Nothing here adds two currencies together. An account that has ever been
 * billed in more than one gets one row per currency, and the portal renders
 * them as separate amounts. `10 KWD + 20 USD = 30` is not a number that
 * exists, and a dashboard that printed it would be wrong in a way a customer
 * would believe.
 *
 * The sums are the server's own, from `amount_due_minor` — the column Wave 2
 * made authoritative. Nothing is recomputed from line items.
 */
final readonly class AccountOverview
{
    /** Enough to be useful on a phone; not a services page. */
    private const RECENT_SERVICES = 5;

    private const UPCOMING_RENEWALS = 5;

    private const RECENT_ACTIVITY = 5;

    /** Renewals beyond this are not "upcoming". */
    private const RENEWAL_HORIZON_DAYS = 45;

    public function __construct(
        private AccountAttention $attention,
        private CustomerActivity $activity,
        private ServiceIdentities $services,
    ) {}

    /**
     * @return array{
     *     attention: list<AttentionItem>,
     *     services: array{total: int, by_state: array<string, int>},
     *     billing: array{due: list<array{currency: string, minor_units: int, invoices: int}>},
     *     renewals: list<array{kind: string, resource_kind: ?string, resource_id: string, identity: ?string, at: string, currency: ?string, minor_units: ?int}>,
     *     unread_notifications: int,
     *     recent: array{services: list<array{kind: string, id: string, identity: ?string, state: string}>},
     * }
     */
    public function for(Customer $customer): array
    {
        $customerId = (string) $customer->getKey();

        $attention = $this->attention->for($customer);
        $recentServiceRows = $this->recentServiceRows($customerId);

        /*
         * One handle resolution for the whole response.
         *
         * The attention list and the recent-services list both hold service
         * ids that have to become `{kind, id, identity}`, and resolving them
         * separately would ask every fulfilling table twice for one dashboard.
         * The ids are collected first, resolved once, and handed to both.
         */
        $handles = $this->handlesFor($attention, $recentServiceRows);

        return [
            'attention' => $this->hydrate($attention, $handles),
            'services' => $this->serviceSummary($customerId),
            'billing' => ['due' => $this->dueByCurrency($customerId)],
            'renewals' => $this->upcomingRenewals($customerId),
            'unread_notifications' => $this->unreadNotifications($customerId),
            'recent' => ['services' => $this->recentServices($recentServiceRows, $handles)],
        ];
    }

    /**
     * Every service id this response needs a handle for, resolved together.
     *
     * @param  list<AttentionItem>  $attention
     * @param  list<object>  $recentServices
     * @return array<string, array{kind: string, id: string, identity: ?string}>
     */
    private function handlesFor(array $attention, array $recentServices): array
    {
        $serviceIds = [];

        foreach ($attention as $item) {
            if ($item->resourceKind === null && $item->resourceId !== null) {
                $serviceIds[] = $item->resourceId;
            }
        }

        foreach ($recentServices as $row) {
            $serviceIds[] = (string) $row->id;
        }

        if ($serviceIds === []) {
            return [];
        }

        return $this->services->handlesFor(array_values(array_unique($serviceIds)));
    }

    /**
     * The activity rows the dashboard shows, from the account feed itself.
     *
     * §30: the dashboard must not have its own history logic. This is the same
     * `CustomerActivity` the `/activity` page reads, asked for a shorter page —
     * so a row cannot read one way on the dashboard and another way in the
     * feed, and there is one place where "what happened" is decided.
     *
     * @return list<ActivityItem>
     */
    public function recentActivity(Customer $customer): array
    {
        return $this->activity->page($customer, null, null, self::RECENT_ACTIVITY)->items;
    }

    /**
     * How many services, and in what state.
     *
     * One grouped count. The states are the customer-facing ones, so a
     * dashboard that says "1 needs attention" agrees with the services index
     * that shows which.
     *
     * @return array{total: int, by_state: array<string, int>}
     */
    private function serviceSummary(string $customerId): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('services')
            ->selectRaw('status, count(*) as total')
            ->where('customer_id', $customerId)
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        return [
            'total' => array_sum($counts),
            'by_state' => $counts,
        ];
    }

    /**
     * What is owed, one row per currency.
     *
     * @return list<array{currency: string, minor_units: int, invoices: int}>
     */
    private function dueByCurrency(string $customerId): array
    {
        return DB::table('invoices')
            ->selectRaw('currency, sum(amount_due_minor) as minor_units, count(*) as invoices')
            ->where('customer_id', $customerId)
            ->where('status', 'open')
            ->where('amount_due_minor', '>', 0)
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(static fn (object $row): array => [
                'currency' => (string) $row->currency,
                'minor_units' => (int) $row->minor_units,
                'invoices' => (int) $row->invoices,
            ])
            ->all();
    }

    /**
     * What is going to be charged, and when.
     *
     * Two kinds of upcoming commercial event, from the two tables that hold a
     * real date: a subscription's next invoice and a name's expiry. Both carry
     * an amount only where the platform has an authoritative one —
     * `recurring_amount_minor` for a subscription — and a domain carries none,
     * because its renewal price comes from the catalogue at the moment of
     * renewal and inventing one here would be quoting a guess.
     *
     * @return list<array{kind: string, resource_kind: ?string, resource_id: string, identity: ?string, at: string, currency: ?string, minor_units: ?int}>
     */
    private function upcomingRenewals(string $customerId): array
    {
        $horizon = now()->addDays(self::RENEWAL_HORIZON_DAYS);

        $subscriptions = DB::table('subscriptions')
            ->select(['id', 'next_invoice_at', 'currency', 'recurring_amount_minor'])
            ->where('customer_id', $customerId)
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->whereNotNull('next_invoice_at')
            ->where('next_invoice_at', '<=', $horizon)
            ->orderBy('next_invoice_at')
            ->limit(self::UPCOMING_RENEWALS)
            ->get()
            ->map(static fn (object $row): array => [
                'kind' => 'subscription',
                'resource_kind' => 'subscription',
                'resource_id' => (string) $row->id,
                'identity' => null,
                'at' => (string) $row->next_invoice_at,
                'currency' => (string) $row->currency,
                'minor_units' => (int) $row->recurring_amount_minor,
            ])
            ->all();

        $domains = DB::table('domains')
            ->select(['id', 'name', 'expires_at'])
            ->where('customer_id', $customerId)
            ->where('state', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $horizon)
            ->orderBy('expires_at')
            ->limit(self::UPCOMING_RENEWALS)
            ->get()
            ->map(static fn (object $row): array => [
                'kind' => 'domain',
                'resource_kind' => 'domain',
                'resource_id' => (string) $row->id,
                'identity' => (string) $row->name,
                'at' => (string) $row->expires_at,
                // No amount: the price is the catalogue's at renewal time.
                'currency' => null,
                'minor_units' => null,
            ])
            ->all();

        $all = [...$subscriptions, ...$domains];

        usort($all, static fn (array $a, array $b): int => [$a['at'], $a['resource_id']] <=> [$b['at'], $b['resource_id']]);

        return array_slice($all, 0, self::UPCOMING_RENEWALS);
    }

    private function unreadNotifications(string $customerId): int
    {
        return DB::table('notifications')
            ->where('customer_id', $customerId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * A few services worth looking at, newest first.
     *
     * Not the services page: five rows and a link. The handle is resolved so
     * each one opens its own resource rather than a list.
     *
     * @param  list<object>  $rows
     * @param  array<string, array{kind: string, id: string, identity: ?string}>  $handles
     * @return list<array{kind: string, id: string, identity: ?string, state: string}>
     */
    private function recentServices(array $rows, array $handles): array
    {
        $summary = [];

        foreach ($rows as $row) {
            $handle = $handles[(string) $row->id] ?? null;

            // A service whose resource row does not exist yet — a build still
            // in flight — has nothing to link to, and is left out rather than
            // linked to a page that would 404.
            if ($handle === null) {
                continue;
            }

            $summary[] = [
                'kind' => $handle['kind'],
                'id' => $handle['id'],
                'identity' => $handle['identity'],
                'state' => (string) $row->status,
            ];
        }

        return $summary;
    }

    /**
     * The service rows the dashboard shows, before their handles are known.
     *
     * Read separately from their presentation so the ids can join the one
     * handle resolution this response makes.
     *
     * @return list<object>
     */
    private function recentServiceRows(string $customerId): array
    {
        return DB::table('services')
            ->select(['id', 'status'])
            ->where('customer_id', $customerId)
            ->whereNotIn('status', ['terminated'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_SERVICES)
            ->get()
            ->all();
    }

    /**
     * Resolve the attention items that named a service rather than a resource.
     *
     * The handles were resolved once for the whole response, so a dashboard
     * with five `needs_review` operations and five recent services costs one
     * resolution rather than ten.
     *
     * @param  list<AttentionItem>  $items
     * @param  array<string, array{kind: string, id: string, identity: ?string}>  $handles
     * @return list<AttentionItem>
     */
    private function hydrate(array $items, array $handles): array
    {
        if ($handles === []) {
            return $items;
        }

        return array_map(
            static function (AttentionItem $item) use ($handles): AttentionItem {
                if ($item->resourceKind !== null || $item->resourceId === null) {
                    return $item;
                }

                $handle = $handles[$item->resourceId] ?? null;

                if ($handle === null) {
                    return $item;
                }

                return new AttentionItem(
                    id: $item->id,
                    kind: $item->kind,
                    severity: $item->severity,
                    occurredAt: $item->occurredAt,
                    resourceKind: $handle['kind'],
                    resourceId: $handle['id'],
                    resourceIdentity: $handle['identity'],
                    reference: $item->reference,
                );
            },
            $items,
        );
    }
}
