<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Compute\Domain\Enums\RemoteTaskStatus;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
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
