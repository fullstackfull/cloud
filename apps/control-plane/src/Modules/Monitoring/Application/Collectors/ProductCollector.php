<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Enums\FileRestoreState;
use Lynomia\Modules\Compute\Domain\Enums\RemoteTaskStatus;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Identity\Domain\Enums\CountryCurrencyChangeState;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationKind;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationState;
use Lynomia\Modules\Support\Domain\Enums\TicketStatus;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;

/**
 * The products this phase built, in numbers an operator can alert on.
 *
 * ---------------------------------------------------------------------------
 * Cardinality
 * ---------------------------------------------------------------------------
 *
 * Every label value on every series here comes from an enum or a fixed list of
 * buckets. Nothing is labelled by customer, email, domain, hostname, address,
 * ticket, service or task identifier — not because the series count would grow
 * (though it would, without limit, with the customer base) but because a
 * metrics endpoint is scraped into a system with different access controls
 * from this one, and a customer's domain name in a label is that domain name
 * in every dashboard and alert built on top of it.
 *
 * Zero-valued series are emitted rather than omitted, so that an alert on
 * `indeterminate > 0` fires the first time one appears instead of waiting for
 * a series to come into existence.
 */
final readonly class ProductCollector implements MetricsCollector
{
    /**
     * Age buckets for the support backlog, in hours.
     *
     * Bounded and few: what a support lead needs is "how many are older than a
     * day", not a histogram they will never read.
     */
    private const array TICKET_AGE_BUCKETS = [1, 4, 24, 72];

    public function name(): string
    {
        return 'product';
    }

    public function collect(): array
    {
        return [
            $this->invitations(),
            $this->members(),
            $this->wallet(),
            $this->tickets(),
            $this->ticketAge(),
            $this->backupDeletion(),
            $this->backupRetention(),
            $this->fileRestores(),
            $this->countryCurrencyChanges(),
            $this->domains(),
            $this->domainOperations(),
            $this->domainRedemptions(),
            $this->wordPressSites(),
            $this->wordPressCopies(),
            $this->termination(),
            $this->drift(),
            $this->providerTasks(),
        ];
    }

    private function invitations(): Metric
    {
        /*
         * Derived in SQL from the three timestamps rather than from a status
         * column, because there is no status column: an invitation's state is
         * which of accepted/declined/revoked has happened, and expiry is a
         * date passing rather than an act.
         */
        $counts = DB::table('customer_invitations')
            ->selectRaw(<<<'SQL'
                case
                    when accepted_at is not null then 'accepted'
                    when declined_at is not null then 'declined'
                    when revoked_at is not null then 'revoked'
                    when expires_at < now() then 'expired'
                    else 'pending'
                end as state,
                count(*) as total
            SQL)
            ->groupBy('state')
            ->pluck('total', 'state')
            ->all();

        $samples = [];

        foreach (['pending', 'accepted', 'declined', 'revoked', 'expired'] as $state) {
            $samples[] = MetricSample::of(['state' => $state], (float) ($counts[$state] ?? 0));
        }

        return Metric::gauge(
            'lynomia_invitation_total',
            'Invitations by state. A rising `pending` count with no accepts is invitation mail not arriving.',
            $samples,
        );
    }

    private function members(): Metric
    {
        $counts = DB::table('customer_members')
            ->selectRaw('role, count(*) as total')
            ->whereNotNull('accepted_at')
            ->groupBy('role')
            ->pluck('total', 'role')
            ->all();

        $samples = [];

        foreach (CustomerRole::cases() as $role) {
            $samples[] = MetricSample::of(['role' => $role->value], (float) ($counts[$role->value] ?? 0));
        }

        return Metric::gauge(
            'lynomia_account_member_total',
            'Accepted account members by role.',
            $samples,
        );
    }

    private function wallet(): Metric
    {
        /*
         * Counts of ledger entries by kind, never balances or amounts. A
         * balance is money and belongs on a financial report somebody is
         * accountable for; a metrics endpoint is scraped by anything on the
         * network holding the token.
         */
        $counts = DB::table('wallet_transactions')
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind')
            ->all();

        $samples = [];

        foreach (WalletTransactionKind::cases() as $kind) {
            $samples[] = MetricSample::of(['kind' => $kind->value], (float) ($counts[$kind->value] ?? 0));
        }

        return Metric::gauge(
            'lynomia_wallet_entry_total',
            'Wallet ledger entries by kind. Counts, never balances or amounts: a balance is money and does not belong on a scrape endpoint.',
            $samples,
        );
    }

    private function tickets(): Metric
    {
        $counts = DB::table('support_tickets')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $samples = [];

        foreach (TicketStatus::cases() as $status) {
            $samples[] = MetricSample::of(['status' => $status->value], (float) ($counts[$status->value] ?? 0));
        }

        return Metric::gauge(
            'lynomia_support_ticket_total',
            'Support tickets by status.',
            $samples,
        );
    }

    private function ticketAge(): Metric
    {
        $now = CarbonImmutable::now();

        /*
         * One query with a FILTER per bucket rather than one query per bucket.
         * Four counts is not a lot, but a loop of queries in a collector is
         * exactly the shape the metrics budget exists to keep out — and the
         * next person to add a bucket would not think twice about it.
         */
        $selects = [];
        $bindings = [];

        foreach (self::TICKET_AGE_BUCKETS as $index => $hours) {
            $selects[] = sprintf('count(*) filter (where created_at < ?) as bucket_%d', $index);
            $bindings[] = $now->subHours($hours);
        }

        /** @var object{bucket_0: int, bucket_1: int, bucket_2: int, bucket_3: int}|null $row */
        $row = DB::table('support_tickets')
            ->selectRaw(implode(', ', $selects), $bindings)
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::WaitingForSupport->value])
            ->first();

        $samples = [];

        foreach (self::TICKET_AGE_BUCKETS as $index => $hours) {
            $key = 'bucket_'.$index;

            $samples[] = MetricSample::of(
                ['older_than_hours' => (string) $hours],
                (float) ($row->{$key} ?? 0),
            );
        }

        return Metric::gauge(
            'lynomia_support_backlog_age',
            'Open tickets waiting on the support team, by how long they have been open. Four buckets, because what a support lead needs is "how many are older than a day".',
            $samples,
        );
    }

    private function backupDeletion(): Metric
    {
        $counts = DB::table('backups')
            ->selectRaw('state, count(*) as total')
            ->whereIn('state', [
                BackupState::DeleteRequested->value,
                BackupState::Deleting->value,
                BackupState::Deleted->value,
                BackupState::NeedsReview->value,
            ])
            ->groupBy('state')
            ->pluck('total', 'state')
            ->all();

        $samples = [];

        foreach ([BackupState::DeleteRequested, BackupState::Deleting, BackupState::Deleted, BackupState::NeedsReview] as $state) {
            $samples[] = MetricSample::of(['state' => $state->value], (float) ($counts[$state->value] ?? 0));
        }

        return Metric::gauge(
            'lynomia_backup_deletion_total',
            'Backups in each stage of removal. A `deleting` count that does not fall is a datastore that accepts deletes and keeps the archive.',
            $samples,
        );
    }

    /**
     * File-level restores by state. `needs_review` is a restore the provider
     * never answered for: the files may or may not have been written, the
     * platform will not try again, and a person settles it.
     */
    private function fileRestores(): Metric
    {
        $counts = DB::table('backup_file_restores')
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $samples = [];

        foreach (FileRestoreState::cases() as $state) {
            $samples[] = MetricSample::of(['state' => $state->value], (float) ($counts[$state->value] ?? 0));
        }

        return Metric::gauge(
            'lynomia_backup_file_restores_total',
            'File-level restores by state. `needs_review` is one the provider did not confirm; it is never retried and waits for a person.',
            $samples,
        );
    }

    /**
     * Requests to change an account's country or currency, by state.
     * `needs_review` is one that was approved and then found a blocker at
     * the moment of applying; nothing was written and a person decides.
     */
    private function countryCurrencyChanges(): Metric
    {
        $counts = DB::table('customer_country_currency_changes')
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $samples = [];

        foreach (CountryCurrencyChangeState::cases() as $state) {
            $samples[] = MetricSample::of(['state' => $state->value], (float) ($counts[$state->value] ?? 0));
        }

        return Metric::gauge(
            'lynomia_country_currency_changes_total',
            'Account country/currency change requests by state. `needs_review` was approved and then held at the last check; `blocked` is waiting on the customer.',
            $samples,
        );
    }

    /**
     * What the retention sweep has left to do, and what it may not touch.
     *
     * Three dispositions, and the useful one is `held`: an archive kept past
     * its own expiry because a departing customer's retention window outranks
     * it. A `due` count that does not fall is the sweep not running or the
     * datastore refusing; a `held` count that never falls is retention windows
     * that never close.
     */
    private function backupRetention(): Metric
    {
        $available = [
            BackupState::Succeeded->value,
            BackupState::Verified->value,
        ];

        /** @var object{due: int|string, held: int|string, within: int|string} $row */
        $row = DB::table('backups')
            ->whereIn('state', $available)
            ->selectRaw(<<<'SQL'
                count(*) filter (
                    where expires_at is not null and expires_at <= now()
                      and (protected_until is null or protected_until <= now())
                ) as due,
                count(*) filter (
                    where protected_until is not null and protected_until > now()
                ) as held,
                count(*) filter (
                    where expires_at is null or expires_at > now()
                ) as within
            SQL)
            ->first();

        return Metric::gauge(
            'lynomia_backup_retention_total',
            'Archives the platform is keeping, by what retention says about them. `due` is the sweep\'s queue; `held` is kept through a cancellation\'s window whatever the plan says.',
            [
                MetricSample::of(['disposition' => 'due'], (float) $row->due),
                MetricSample::of(['disposition' => 'held'], (float) $row->held),
                MetricSample::of(['disposition' => 'within_policy'], (float) $row->within),
            ],
        );
    }

    /**
     * Names held, and the two numbers that decide whether somebody has to act.
     *
     * `expiring` is a countdown, not a queue depth: a domain that lapses is
     * gone, so this is one of the few gauges on the platform where the right
     * response to a rising number is to look at it today rather than at the
     * end of the week.
     *
     * `unsure` counts the names the Timeout Rule left behind that
     * reconciliation could not settle. It should sit at zero. A number that
     * stays above zero across several sweeps means the reconciler is asking a
     * registrar that cannot answer, and a customer is waiting.
     */
    private function domains(): Metric
    {
        $unsure = [
            DomainState::Indeterminate->value,
            DomainState::NeedsReview->value,
        ];

        /** @var object{held: int|string, expiring: int|string, unsure: int|string, lapsing: int|string} $row */
        $row = DB::table('domains')
            ->selectRaw(<<<'SQL'
                count(*) filter (where state = 'active') as held,
                count(*) filter (
                    where state = 'active' and expires_at is not null
                      and expires_at <= now() + interval '45 days'
                ) as expiring,
                count(*) filter (where state in (?, ?)) as unsure,
                count(*) filter (where state in ('expired', 'grace', 'redemption')) as lapsing
            SQL, $unsure)
            ->first();

        return Metric::gauge(
            'lynomia_domains_total',
            'Names this platform holds. `expiring` is inside the warning window; `unsure` is what the Timeout Rule left and reconciliation could not settle, and should sit at zero.',
            [
                MetricSample::of(['disposition' => 'held'], (float) $row->held),
                MetricSample::of(['disposition' => 'expiring'], (float) $row->expiring),
                MetricSample::of(['disposition' => 'unsure'], (float) $row->unsure),
                MetricSample::of(['disposition' => 'lapsing'], (float) $row->lapsing),
            ],
        );
    }

    /**
     * Attempts that spend money, by whether they finished.
     *
     * `in_flight` includes a transfer waiting on a losing registrar, which can
     * sit for five days quite legitimately — so this gauge is read beside the
     * domain one rather than alerted on by itself.
     */
    private function domainOperations(): Metric
    {
        /** @var object{in_flight: int|string, needs_attention: int|string, failed: int|string} $row */
        $row = DB::table('domain_operations')
            ->selectRaw(<<<'SQL'
                count(*) filter (
                    where state in ('requested', 'queued', 'running', 'awaiting_registry')
                ) as in_flight,
                count(*) filter (where state in ('indeterminate', 'needs_review')) as needs_attention,
                count(*) filter (where state = 'failed') as failed
            SQL)
            ->first();

        return Metric::gauge(
            'lynomia_domain_operations_total',
            'Registrations, renewals and transfers by disposition. `needs_attention` is money spent on an outcome nobody has established.',
            [
                MetricSample::of(['disposition' => 'in_flight'], (float) $row->in_flight),
                MetricSample::of(['disposition' => 'needs_attention'], (float) $row->needs_attention),
                MetricSample::of(['disposition' => 'failed'], (float) $row->failed),
            ],
        );
    }

    /**
     * Recoveries of lapsed names, by state.
     *
     * Its own series because a redemption is the one domain operation with a
     * penalty on it and a registry clock behind it: an `indeterminate` here
     * is money spent on a name that may be about to be released to anybody,
     * and the alert on it pages for that reason. Labels are the operation's
     * state enum and nothing else.
     */
    private function domainRedemptions(): Metric
    {
        $counts = DB::table('domain_operations')
            ->selectRaw('state, count(*) as total')
            ->where('kind', DomainOperationKind::Redeem->value)
            ->groupBy('state')
            ->pluck('total', 'state');

        $samples = [];

        foreach (DomainOperationState::cases() as $state) {
            $samples[] = MetricSample::of(['state' => $state->value], (float) ($counts[$state->value] ?? 0));
        }

        return Metric::gauge(
            'lynomia_domain_redemptions_total',
            'Recoveries of lapsed names by state. `indeterminate` is a penalty paid for a name the registry has not confirmed restoring; it is never retried and waits for reconciliation or a person.',
            $samples,
        );
    }

    /**
     * WordPress sites, by how much of the promise is actually true.
     *
     * `live` counts only sites this platform has fetched and found WordPress
     * on. Every other number here is a site somebody is waiting on, and
     * `stuck` is the one worth alerting on: an install nobody can repeat
     * safely, waiting for a person.
     */
    private function wordPressSites(): Metric
    {
        /** @var object{live: int|string, building: int|string, waiting_on_dns: int|string, stuck: int|string} $row */
        $row = DB::table('wordpress_sites')
            ->selectRaw(<<<'SQL'
                count(*) filter (where state = 'ready' and verified_at is not null) as live,
                count(*) filter (where state in ('requested', 'installing', 'awaiting_certificate')) as building,
                count(*) filter (where state = 'awaiting_dns') as waiting_on_dns,
                count(*) filter (where state in ('indeterminate', 'needs_review', 'failed')) as stuck
            SQL)
            ->first();

        return Metric::gauge(
            'lynomia_wordpress_sites_total',
            'WordPress sites by disposition. `live` counts only sites the platform fetched and found WordPress on; `waiting_on_dns` is waiting on the customer, and `stuck` is waiting on a person here.',
            [
                MetricSample::of(['disposition' => 'live'], (float) $row->live),
                MetricSample::of(['disposition' => 'building'], (float) $row->building),
                MetricSample::of(['disposition' => 'waiting_on_dns'], (float) $row->waiting_on_dns),
                MetricSample::of(['disposition' => 'stuck'], (float) $row->stuck),
            ],
        );
    }

    /**
     * Copies and pushes of WordPress sites, by kind and state. An
     * `indeterminate` push is the one worth an alert: production may be
     * half-overwritten and nobody has confirmed either way.
     */
    private function wordPressCopies(): Metric
    {
        $counts = DB::table('wordpress_site_operations')
            ->selectRaw('kind, state, count(*) as total')
            ->groupBy('kind', 'state')
            ->get()
            ->keyBy(static fn (object $row): string => $row->kind.'|'.$row->state);

        $samples = [];

        foreach (WordPressOperationKind::cases() as $kind) {
            foreach (WordPressOperationState::cases() as $state) {
                $samples[] = MetricSample::of(
                    ['kind' => $kind->value, 'state' => $state->value],
                    (float) ($counts[$kind->value.'|'.$state->value]->total ?? 0),
                );
            }
        }

        return Metric::gauge(
            'lynomia_wordpress_site_operations_total',
            'Copies and pushes of WordPress sites by kind and state. An indeterminate push_to_production is a live site that may be half-overwritten; it is never retried.',
            $samples,
        );
    }

    private function termination(): Metric
    {
        $counts = DB::table('services')
            ->selectRaw('ended_reason, count(*) as total')
            ->whereNotNull('retention_ends_at')
            ->where('status', ServiceStatus::Suspended->value)
            ->groupBy('ended_reason')
            ->pluck('total', 'ended_reason')
            ->all();

        $samples = [];

        foreach (['customer_cancelled', 'non_payment', 'operator'] as $reason) {
            $samples[] = MetricSample::of(['reason' => $reason], (float) ($counts[$reason] ?? 0));
        }

        return Metric::gauge(
            'lynomia_service_retention_window_open',
            'Stopped services whose data is still inside its retention window, by why they stopped. Only `customer_cancelled` is ever ended automatically.',
            $samples,
        );
    }

    private function drift(): Metric
    {
        /*
         * By resource type and kind — both enums — and never by the provider
         * reference, which is a hostname, a username or a record value
         * depending on which sweep wrote it.
         */
        $rows = DB::table('resource_drifts')
            ->selectRaw('resource_type, kind, count(*) as total')
            ->where('status', DriftStatus::Open->value)
            ->groupBy('resource_type', 'kind')
            ->get();

        $samples = [];

        foreach ($rows as $row) {
            $samples[] = MetricSample::of(
                ['resource' => (string) $row->resource_type, 'kind' => (string) $row->kind],
                (float) $row->total,
            );
        }

        return Metric::gauge(
            'lynomia_open_drift_total',
            'Unresolved disagreements between the platform and a provider, by what disagrees and how.',
            $samples,
        );
    }

    private function providerTasks(): Metric
    {
        $counts = DB::table('provisioning_jobs')
            ->selectRaw('coalesce(remote_task_state, \'unconfirmed\') as state, count(*) as total')
            ->where('status', ProvisioningJobStatus::Succeeded->value)
            ->whereNotNull('remote_job_id')
            ->groupBy('state')
            ->pluck('total', 'state')
            ->all();

        $samples = [];

        $states = ['unconfirmed', ...array_map(
            static fn (RemoteTaskStatus $status): string => $status->value,
            RemoteTaskStatus::cases(),
        )];

        foreach ($states as $state) {
            $samples[] = MetricSample::of(['state' => $state], (float) ($counts[$state] ?? 0));
        }

        return Metric::gauge(
            'lynomia_provider_task_total',
            'Tasks behind jobs the platform has already called a success. `unconfirmed` is the queue the poller works through; a rising one means the hypervisor is not being asked.',
            $samples,
        );
    }
}
