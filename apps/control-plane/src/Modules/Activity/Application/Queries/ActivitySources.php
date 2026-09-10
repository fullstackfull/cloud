<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Application\Queries;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Activity\Domain\Enums\ActivityCategory;
use Lynomia\Modules\Activity\Domain\Enums\ActorType;

/**
 * The branches the activity feed is a union of.
 *
 * Each method returns one `SELECT` over one existing durable table, shaped
 * into the common column list below. Nothing here writes; nothing here is a
 * second copy of business state. The feed is a projection of the tables that
 * already are the truth, computed when it is read — so it cannot drift from
 * them, needs no backfill, and no business operation can fail because
 * recording activity failed.
 *
 * The common columns every branch must produce:
 *
 *   occurred_at        when it happened (timestamptz)
 *   activity_id        'source:rowid' — unique, and the cursor's tie-breaker
 *   category           an ActivityCategory value, as a literal
 *   source_kind        the source's own kind/verb, mapped to a message code
 *                      in PHP where the mapping is exhaustive and explicit
 *   source_state       the source's own state, mapped the same way
 *   resource_kind      a Wave 3 resource kind, or null
 *   resource_id        the resource's own id, or null
 *   resource_identity  the name the customer knows it by where the source
 *                      holds it, else null and resolved in one batched pass
 *   actor_type         an ActorType value
 *   actor_user_id      a user id to resolve a display name for, or null
 *   reference          something quotable to support, or null
 *
 * Two rules hold in every branch:
 *
 *  1. **Columns are named, never `select('*')`.** The source tables carry
 *     `last_error`, `payload`, `result`, `node_name`, `datastore`,
 *     `bmc_protocol`, `provider_reference`, `failure_message`. A star select
 *     would put all of them one careless `->toArray()` away from a customer.
 *
 *  2. **The customer scope is on every branch**, on the branch's own
 *     `customer_id`, not applied afterwards to the union. A branch that
 *     forgot it would leak the whole platform's history through one endpoint,
 *     and that is precisely the risk §49 names.
 */
final readonly class ActivitySources
{
    /**
     * Kinds of provisioning job that another branch reports better.
     *
     * A WordPress copy and a push each write two rows: a provisioning job for
     * the engine, and a `wordpress_site_operations` row that carries the
     * scope, the impact and — unlike the job — who asked for it. Publishing
     * both would show the customer the same act twice, so the job is dropped
     * here and the richer row wins.
     */
    private const SUPERSEDED_JOB_KINDS = ['copy_wordpress_site', 'push_wordpress_to_production'];

    /**
     * Provisioning work: builds, power actions, rebuilds, plan changes.
     *
     * The spine of the feed. `created_at` is when the customer asked, which is
     * the moment they are looking for when they scroll back — not
     * `finished_at`, which would file a rebuild that started last night under
     * this morning.
     */
    public function provisioningJobs(string $customerId): QueryBuilder
    {
        return DB::table('provisioning_jobs')
            ->select([
                DB::raw('created_at as occurred_at'),
                DB::raw("'provisioning_job:' || id as activity_id"),
                DB::raw("'' as category"),
                DB::raw('kind as source_kind'),
                DB::raw('status as source_state'),
                DB::raw('null::text as resource_kind'),
                DB::raw('service_id as resource_id'),
                DB::raw('null::text as resource_identity'),
                DB::raw("case when requested_by_user_id is null then '".ActorType::System->value."' else '".ActorType::CustomerUser->value."' end as actor_type"),
                DB::raw('requested_by_user_id as actor_user_id'),
                DB::raw('null::text as reference'),
            ])
            ->where('customer_id', $customerId)
            ->whereNotIn('kind', self::SUPERSEDED_JOB_KINDS);
    }

    /**
     * Registrar work on a name: register, renew, transfer, redeem.
     *
     * The one branch whose rows can legitimately be `indeterminate`, and the
     * reason that state exists in the vocabulary at all. The row carries the
     * name, so no identity resolution is needed — and no actor, because these
     * rows are written by orders and by the renewal scheduler; the person who
     * placed the order is recorded on the order's own transition.
     */
    public function domainOperations(string $customerId): QueryBuilder
    {
        return DB::table('domain_operations')
            ->select([
                DB::raw('created_at as occurred_at'),
                DB::raw("'domain_operation:' || id as activity_id"),
                DB::raw("'".ActivityCategory::Domains->value."' as category"),
                DB::raw('kind as source_kind'),
                DB::raw('state as source_state'),
                DB::raw("'domain' as resource_kind"),
                DB::raw('domain_id as resource_id'),
                DB::raw('name as resource_identity'),
                DB::raw("'".ActorType::Unknown->value."' as actor_type"),
                DB::raw('null::text as actor_user_id'),
                DB::raw('null::text as reference'),
            ])
            ->where('customer_id', $customerId);
    }

    /**
     * WordPress staging, cloning and pushing.
     *
     * Records its requester, so this is one of the branches that can answer
     * "who". The `impact` column is not selected: it is the toolkit's own
     * description of what a push would overwrite, and it belongs on the site's
     * page beside the confirmation, not in a history row.
     */
    public function wordpressOperations(string $customerId): QueryBuilder
    {
        return DB::table('wordpress_site_operations')
            ->select([
                DB::raw('created_at as occurred_at'),
                DB::raw("'wordpress_operation:' || id as activity_id"),
                DB::raw("'".ActivityCategory::Hosting->value."' as category"),
                DB::raw('kind as source_kind'),
                DB::raw('state as source_state'),
                DB::raw("'wordpress' as resource_kind"),
                DB::raw('wordpress_site_id as resource_id'),
                DB::raw('null::text as resource_identity'),
                DB::raw("case when requested_by_user_id is null then '".ActorType::System->value."' else '".ActorType::CustomerUser->value."' end as actor_type"),
                DB::raw('requested_by_user_id as actor_user_id'),
                DB::raw('null::text as reference'),
            ])
            ->where('customer_id', $customerId);
    }

    /**
     * Backups, and the restores that read them back.
     *
     * `node_name`, `datastore`, `provider_task_id`, `archive_id` and
     * `verification_task_id` all sit on this table and none is selected. What
     * a customer needs from a backup row in a feed is that it happened, to
     * which machine, and whether it worked.
     */
    public function backups(string $customerId): QueryBuilder
    {
        return DB::table('backups')
            ->select([
                DB::raw('created_at as occurred_at'),
                DB::raw("'backup:' || id as activity_id"),
                DB::raw("'".ActivityCategory::Backups->value."' as category"),
                DB::raw("'backup' as source_kind"),
                DB::raw('state as source_state'),
                DB::raw("'vps' as resource_kind"),
                DB::raw('service_id as resource_id'),
                DB::raw('null::text as resource_identity'),
                DB::raw("case when requested_by_user_id is null then '".ActorType::System->value."' else '".ActorType::CustomerUser->value."' end as actor_type"),
                DB::raw('requested_by_user_id as actor_user_id'),
                DB::raw('null::text as reference'),
            ])
            ->where('customer_id', $customerId);
    }

    public function fileRestores(string $customerId): QueryBuilder
    {
        return DB::table('backup_file_restores')
            ->select([
                DB::raw('created_at as occurred_at'),
                DB::raw("'file_restore:' || id as activity_id"),
                DB::raw("'".ActivityCategory::Backups->value."' as category"),
                DB::raw("'file_restore' as source_kind"),
                DB::raw('state as source_state'),
                DB::raw("'vps' as resource_kind"),
                DB::raw('service_id as resource_id'),
                DB::raw('null::text as resource_identity'),
                DB::raw("case when requested_by_user_id is null then '".ActorType::System->value."' else '".ActorType::CustomerUser->value."' end as actor_type"),
                DB::raw('requested_by_user_id as actor_user_id'),
                DB::raw('null::text as reference'),
            ])
            ->where('customer_id', $customerId);
    }

    /**
     * A zone import that was applied.
     *
     * The counts on the row — added, updated, removed — are what made the
     * import worth recording, and they are exactly what a history line cannot
     * carry without an open metadata bag. The row links the zone; the zone's
     * own page holds the detail.
     */
    public function zoneImports(string $customerId): QueryBuilder
    {
        return DB::table('dns_zone_imports')
            ->select([
                DB::raw('created_at as occurred_at'),
                DB::raw("'zone_import:' || id as activity_id"),
                DB::raw("'".ActivityCategory::Domains->value."' as category"),
                DB::raw("'zone_import' as source_kind"),
                DB::raw('outcome as source_state'),
                DB::raw("'dns' as resource_kind"),
                DB::raw('dns_zone_id as resource_id'),
                DB::raw('null::text as resource_identity'),
                DB::raw("case when requested_by_user_id is null then '".ActorType::System->value."' else '".ActorType::CustomerUser->value."' end as actor_type"),
                DB::raw('requested_by_user_id as actor_user_id'),
                DB::raw('null::text as reference'),
            ])
            ->where('customer_id', $customerId);
    }

    /**
     * An order changing hands or state.
     *
     * The only source with a typed actor already: `actor_type` distinguishes a
     * customer's own submission from the platform's own progression, which is
     * the distinction this feed needs everywhere and has to synthesise
     * elsewhere. `context` and `reason` are not selected — the first is a
     * free-form map, the second is written for operators.
     *
     * The scope is a join rather than a column: transitions hang off orders,
     * and the order holds the customer. A `whereIn` over the customer's order
     * ids would have to be materialised first; the join keeps the branch one
     * indexed read.
     */
    public function orderTransitions(string $customerId): QueryBuilder
    {
        return DB::table('order_transitions')
            ->join('orders', 'orders.id', '=', 'order_transitions.order_id')
            ->select([
                DB::raw('order_transitions.created_at as occurred_at'),
                DB::raw("'order_transition:' || order_transitions.id as activity_id"),
                DB::raw("'".ActivityCategory::Billing->value."' as category"),
                DB::raw("'order' as source_kind"),
                DB::raw('order_transitions.to_status as source_state'),
                DB::raw("'order' as resource_kind"),
                DB::raw('order_transitions.order_id as resource_id'),
                DB::raw('null::text as resource_identity'),
                DB::raw("case when order_transitions.actor_user_id is not null then '".ActorType::CustomerUser->value."' else '".ActorType::System->value."' end as actor_type"),
                DB::raw('order_transitions.actor_user_id as actor_user_id'),
                DB::raw('null::text as reference'),
            ])
            ->where('orders.customer_id', $customerId);
    }

    /**
     * An invoice being issued, and an invoice being paid.
     *
     * Two events from one row, so two branches. The alternative — one row with
     * whichever timestamp is latest — would lose the issuing the moment the
     * invoice was paid, and a customer scrolling back would find money leaving
     * their account with no bill before it.
     *
     * `number` is the reference: it is what the customer sees on the document
     * and what support asks for.
     */
    public function invoicesIssued(string $customerId): QueryBuilder
    {
        return DB::table('invoices')
            ->select([
                DB::raw('issued_at as occurred_at'),
                DB::raw("'invoice_issued:' || id as activity_id"),
                DB::raw("'".ActivityCategory::Billing->value."' as category"),
                DB::raw("'invoice_issued' as source_kind"),
                DB::raw("'issued' as source_state"),
                DB::raw("'invoice' as resource_kind"),
                DB::raw('id as resource_id'),
                DB::raw('number as resource_identity'),
                DB::raw("'".ActorType::System->value."' as actor_type"),
                DB::raw('null::text as actor_user_id'),
                DB::raw('number as reference'),
            ])
            ->where('customer_id', $customerId)
            ->whereNotNull('issued_at');
    }

    public function invoicesPaid(string $customerId): QueryBuilder
    {
        return DB::table('invoices')
            ->select([
                DB::raw('paid_at as occurred_at'),
                DB::raw("'invoice_paid:' || id as activity_id"),
                DB::raw("'".ActivityCategory::Billing->value."' as category"),
                DB::raw("'invoice_paid' as source_kind"),
                DB::raw("'paid' as source_state"),
                DB::raw("'invoice' as resource_kind"),
                DB::raw('id as resource_id'),
                DB::raw('number as resource_identity'),
                DB::raw("'".ActorType::System->value."' as actor_type"),
                DB::raw('null::text as actor_user_id'),
                DB::raw('number as reference'),
            ])
            ->where('customer_id', $customerId)
            ->whereNotNull('paid_at');
    }

    /**
     * A support request being opened.
     *
     * Its later replies are the thread's own history and are read there; what
     * belongs in an account feed is that the conversation exists, who started
     * it and where it got to. `subject` is the customer's own words and is
     * published as the identity; the reference is the one support quotes.
     */
    public function supportTickets(string $customerId): QueryBuilder
    {
        return DB::table('support_tickets')
            ->select([
                DB::raw('created_at as occurred_at'),
                DB::raw("'support_ticket:' || id as activity_id"),
                DB::raw("'".ActivityCategory::Support->value."' as category"),
                DB::raw("'support_ticket' as source_kind"),
                DB::raw('status as source_state'),
                DB::raw('null::text as resource_kind'),
                DB::raw('id as resource_id'),
                DB::raw('subject as resource_identity'),
                DB::raw("case when opened_by_user_id is null then '".ActorType::System->value."' else '".ActorType::CustomerUser->value."' end as actor_type"),
                DB::raw('opened_by_user_id as actor_user_id'),
                DB::raw('reference as reference'),
            ])
            ->where('customer_id', $customerId);
    }

    /**
     * Every branch, in one list.
     *
     * @return list<QueryBuilder>
     */
    public function all(string $customerId): array
    {
        return [
            $this->provisioningJobs($customerId),
            $this->domainOperations($customerId),
            $this->wordpressOperations($customerId),
            $this->backups($customerId),
            $this->fileRestores($customerId),
            $this->zoneImports($customerId),
            $this->orderTransitions($customerId),
            $this->invoicesIssued($customerId),
            $this->invoicesPaid($customerId),
            $this->supportTickets($customerId),
        ];
    }

    /**
     * The branches that can produce a given category.
     *
     * A category filter that ran over the union would still read every branch;
     * choosing the branches instead means asking about domains costs two
     * indexed reads rather than ten.
     *
     * The provisioning branch appears under three categories because one table
     * holds machines, hosting and WordPress work. It is filtered by kind so
     * that "cloud" does not return a hosting account's build.
     *
     * @return list<QueryBuilder>
     */
    public function forCategory(string $customerId, ActivityCategory $category): array
    {
        return match ($category) {
            ActivityCategory::Cloud => [
                $this->provisioningJobs($customerId)->whereIn('kind', [
                    'create_vps', 'destroy_vps', 'start', 'stop', 'restart',
                    'reinstall_vps', 'reinstall_dedicated', 'resize', 'provision_dedicated',
                ]),
            ],
            ActivityCategory::Hosting => [
                $this->provisioningJobs($customerId)->whereIn('kind', [
                    'create_hosting_account', 'change_hosting_package', 'install_wordpress',
                ]),
                $this->wordpressOperations($customerId),
            ],
            ActivityCategory::Domains => [
                $this->domainOperations($customerId),
                $this->zoneImports($customerId),
            ],
            ActivityCategory::Backups => [
                $this->backups($customerId),
                $this->fileRestores($customerId),
            ],
            ActivityCategory::Billing => [
                $this->orderTransitions($customerId),
                $this->invoicesIssued($customerId),
                $this->invoicesPaid($customerId),
            ],
            ActivityCategory::Support => [
                $this->supportTickets($customerId),
            ],
        };
    }
}
