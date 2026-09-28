<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Provisioning\Application\Actions\RecordDrift;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use Lynomia\Modules\SharedHosting\Domain\DTOs\RemoteAccount;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PDOException;
use Throwable;

/**
 * Compares what the platform believes about a hosting node with what the panel
 * is actually holding.
 *
 * `listAccounts()` has been implemented against cPanel and DirectAdmin since
 * Phase 12 and had no caller, so the platform's belief that a customer had a
 * working account rested entirely on its own record of having created one.
 * Four disagreements, and each is a different kind of bad day:
 *
 *  - **Missing at the panel.** The platform says the account exists and the
 *    panel has never heard of it. Critical, and in the worst direction: the
 *    customer is being billed for a website that is not being served, and the
 *    first person to notice is them. A pending account is not reported until
 *    it has been pending longer than a build takes: until then it is a build
 *    the panel has not been asked for, or has not finished.
 *  - **Orphan at the panel.** An account the platform never created — made by
 *    hand during an incident, or left behind by a create whose answer was
 *    lost. Occupying disk nobody is billing for.
 *  - **Suspension mismatch.** The two disagree about whether the account is
 *    switched off. Either direction is real: a customer using what they have
 *    not paid for, or a paying customer whose site is down.
 *  - **Capacity mismatch.** `account_count` and the rows disagree. An
 *    over-counted node refuses accounts it could hold; an under-counted one
 *    oversells its disk.
 *
 * ---------------------------------------------------------------------------
 * Read-only towards the panel, and towards the platform's own rows
 * ---------------------------------------------------------------------------
 *
 * Nothing here creates, deletes, suspends or adopts anything. An account whose
 * provenance nobody knows must not be handed to a customer as theirs, and an
 * account the platform cannot see must not be terminated on the strength of
 * one listing that might have paged badly. Every automated remedy for drift is
 * one bug away from deleting production; the platform records, alerts, and
 * waits for a person.
 */
final readonly class ReconcileHostingNodes
{
    private const string RESOURCE = 'hosting_account';

    private const string NODE_RESOURCE = 'hosting_node';

    /**
     * How long a pending account the panel does not list is taken for a build
     * in progress rather than an account missing at the panel.
     *
     * Longer than an unattended build can take: config/provisioning.php gives
     * create_hosting_account 300 seconds and three attempts with 30, 120 and
     * 600 seconds between them, about 28 minutes in all. After this, a pending
     * account the panel does not list is recorded as missing at the panel,
     * critical like any other — the customer has paid for an account that was
     * never built — with the moment it has been pending since. Nothing else
     * reports a pending hosting account that stays pending: provisioning's
     * stale sweep quarantines the job, not the account.
     */
    private const int PENDING_BUILD_WINDOW_MINUTES = 60;

    public function __construct(
        private HostingProviderFactory $providers,
        private RecordDrift $drift,
    ) {}

    /**
     * `nodes` counts the nodes whose accounts were compared; `unread` those
     * whose listing the adapter refused or the panel did not answer; `failed`
     * those whose reconciliation failed any other way ({@see self::stopped()}).
     *
     * @return array{nodes: int, accounts: int, drifts: int, unread: int, failed: int}
     */
    public function execute(): array
    {
        $nodes = 0;
        $accounts = 0;
        $drifts = 0;
        $unread = 0;
        $failed = 0;

        foreach ($this->reachableNodes() as $node) {
            try {
                $listed = $this->providers->for($node)->listAccounts($node);
            } catch (HostingProviderException $e) {
                /*
                 * A panel that will not answer is not drift. Nothing is
                 * concluded — a sweep that recorded "missing at the panel" for
                 * every account on a node whose API was down would report an
                 * outage as data loss, and bury the one real missing account
                 * in the middle of it.
                 *
                 * But it is recorded, and it goes to the back. This used to
                 * be a bare `continue`: no log, nothing on the node, and
                 * `reconciled_at` left as it was — which, null or old, is
                 * what put the node at the front of the next sweep, so a
                 * batch's worth of unreadable nodes held the front for ever
                 * and no other node was compared again. The attempt is
                 * stamped (it is what the sweep orders by), the refusal is
                 * kept on the node where the operator's node list shows it,
                 * and a warning is logged. `reconciled_at` is not touched:
                 * nothing was compared.
                 */
                $node->forceFill([
                    'reconcile_attempted_at' => now(),
                    'reconcile_error' => $e->getMessage(),
                ])->save();

                Log::warning('A hosting node\'s account listing could not be read, so its accounts were not compared.', [
                    'node' => $node->slug,
                    'panel' => $node->panel->value,
                    'error' => $e->getMessage(),
                ]);

                $unread++;

                continue;
            } catch (Throwable $e) {
                // Anything else on the way to the listing — a fault in an
                // adapter — stops this node, not the sweep: see the comparison
                // below. (A node whose credential is not configured is not
                // this: both adapters turn that into a HostingProviderException,
                // so it is counted as a listing not read, above.)
                self::rethrowWhatAbortsTheCallersTransaction($e);

                $this->stopped($node, $e, 'asking for its account listing');
                $failed++;

                continue;
            }

            try {
                /*
                 * One node's comparison is its own transaction (a savepoint
                 * when the caller already holds one). Anything else that goes
                 * wrong while comparing it — a drift the database will not
                 * take, a bug — used to escape execute() before the node was
                 * stamped: the sweep ended there, the node was still the least
                 * recently asked, and it was first again on the next run and
                 * every run after, so no node behind it was ever compared.
                 *
                 * Now the failure rolls back what this node had recorded, so
                 * nothing half-concluded is left behind and the connection is
                 * usable again (a statement that failed inside a transaction
                 * leaves every later one refused until it is rolled back), and
                 * it is kept on the node, logged, and the sweep moves on.
                 *
                 * Not every failure: {@see self::rethrowWhatAbortsTheCallersTransaction()}
                 * lets out, as it was thrown, a deadlock or serialization
                 * failure while a caller's transaction is open, and a
                 * statement refused because the transaction was already
                 * aborted; it says why. And a failure of the stamp in
                 * stopped() itself is not caught: it leaves execute() as any
                 * failure did before.
                 */
                [$counted, $found] = DB::transaction(function () use ($node, $listed): array {
                    /*
                     * The node's row first, locked until this transaction
                     * ends. Every drift below is serialised on RecordDrift's
                     * own advisory lock, which inside this transaction is held
                     * until the node commits; two sweeps overlapping on one
                     * node that met the drifts in different orders (their
                     * listings came back in different orders) each held one
                     * the other wanted, and PostgreSQL failed one of them with
                     * a deadlock. With the node locked first, the second sweep
                     * waits here, before it has taken any drift lock, and
                     * compares once the first has committed.
                     * TwoOverlappingHostingSweepsInTwoProcessesTest holds it.
                     *
                     * And the row that lock returns is the one everything
                     * below reads. $node was read before the listing's HTTP
                     * call, and an order that reserved a slot on this node
                     * while the panel was answering has since moved
                     * account_count and added its row: checked against the old
                     * count and the new rows, the ledger looked one short and
                     * a spec_mismatch was recorded that was not there.
                     *
                     * The drifts are recorded in one order whatever node or
                     * listing they came from; see recordInOrder().
                     */
                    $locked = HostingNode::query()->whereKey($node->getKey())->lockForUpdate()->firstOrFail();

                    /** @var list<HostingAccount> $rows */
                    $rows = HostingAccount::query()
                        ->where('hosting_node_id', $locked->getKey())
                        ->get()
                        ->all();

                    $found = $this->recordInOrder([
                        ...$this->compare($locked, $rows, $listed),
                        ...$this->checkCapacity($locked, $rows),
                    ]);

                    $node->forceFill([
                        'reconciled_at' => now(),
                        'reconcile_attempted_at' => now(),
                        'reconcile_error' => null,
                    ])->save();

                    return [count($rows), $found];
                });
            } catch (Throwable $e) {
                self::rethrowWhatAbortsTheCallersTransaction($e);

                $this->stopped($node, $e, 'comparing its account listing with the platform\'s accounts');
                $failed++;

                continue;
            }

            $nodes++;
            $accounts += $counted;
            $drifts += $found;
        }

        return ['nodes' => $nodes, 'accounts' => $accounts, 'drifts' => $drifts, 'unread' => $unread, 'failed' => $failed];
    }

    /**
     * A failure no node-level record can survive, let out as it was thrown.
     *
     * Two kinds, looked for in the exception and every exception it wraps.
     *
     * A deadlock (40P01) or serialization failure (40001) while a caller's
     * transaction is open. When Laravel recognises a concurrency failure in a
     * nested transaction it rethrows it (as a DeadlockException wrapping it)
     * without rolling back to the savepoint, so the caller's transaction is
     * left aborted, every later statement on it is refused with 25P02, and
     * stamping the node would hide the original behind that. Whether this
     * particular one was rolled back or not, a concurrency failure inside a
     * caller's transaction is the caller's to retry. With no caller's
     * transaction, the node's own transaction was the outermost and was rolled
     * back whole, so the failure is recorded on the node like any other.
     *
     * Known by the SQLSTATE as well as by Laravel's detector, because the
     * detector knows a deadlock only by its English message ("deadlock
     * detected"), and PostgreSQL words its messages in the server's
     * lc_messages: a deadlock reported in another language is still 40P01.
     *
     * And a statement refused because a transaction was already aborted
     * (25P02). What this changes is which exception leaves execute(). When the
     * aborted transaction is the caller's, stamping the node is refused the
     * same way, and without this the refusal of stopped()'s first statement
     * would leave in place of the one the node's work met.
     * AFailureWhileReconcilingOneNodeStopsOnlyThatNodeTest::a_statement_refused_because_the_callers_transaction_was_aborted_is_let_out_as_it_was_thrown()
     * holds that. It is let out at any level, so a 25P02 raised inside the
     * node's own transaction ends the sweep too, although that transaction
     * has been rolled back.
     */
    private static function rethrowWhatAbortsTheCallersTransaction(Throwable $e): void
    {
        $detector = new ConcurrencyErrorDetector;

        for ($link = $e; $link !== null; $link = $link->getPrevious()) {
            $sqlState = $link instanceof PDOException ? (string) ($link->errorInfo[0] ?? $link->getCode()) : null;

            if ($sqlState === '25P02') {
                throw $e;
            }

            $concurrency = $detector->causedByConcurrencyError($link) || in_array($sqlState, ['40P01', '40001'], true);

            if ($concurrency && DB::transactionLevel() > 0) {
                throw $e;
            }
        }
    }

    /**
     * A node whose reconciliation failed for a reason other than an
     * unreadable listing: the attempt stamped, so the node goes behind the
     * others; the failure kept on the node for the operator's node list, by
     * its class only — the message can carry a statement and its values, and
     * the log is where those go; and the whole of it logged. `reconciled_at`
     * is not touched: nothing was concluded.
     *
     * The node is read again first. The comparison's own success stamp
     * (`reconciled_at` now, `reconcile_error` null) was filled in on this
     * copy before its save failed, and the rollback does not unfill it; saved
     * as it stands, this would write that `reconciled_at` beside the error.
     */
    private function stopped(HostingNode $node, Throwable $e, string $while): void
    {
        $node->refresh()->forceFill([
            'reconcile_attempted_at' => now(),
            'reconcile_error' => sprintf('Reconciliation failed while %s (%s), so nothing was concluded. The log has the detail.', $while, $e::class),
        ])->save();

        Log::error('A hosting node\'s reconciliation failed, so nothing was concluded about its accounts.', [
            'node' => $node->slug,
            'panel' => $node->panel->value,
            'while' => $while,
            'exception' => $e,
        ]);
    }

    /**
     * What this node's comparison found, as RecordDrift arguments, recorded
     * by the caller through recordInOrder().
     *
     * @param  list<HostingAccount>  $rows
     * @param  list<RemoteAccount>  $listed
     * @return list<array<string, mixed>>
     */
    private function compare(HostingNode $node, array $rows, array $listed): array
    {
        $drifts = [];

        /** @var array<string, RemoteAccount> $byUsername */
        $byUsername = [];

        foreach ($listed as $remote) {
            $byUsername[strtolower($remote->username)] = $remote;
        }

        $known = [];

        foreach ($rows as $row) {
            $username = strtolower($row->username);
            $known[] = $username;
            $remote = $byUsername[$username] ?? null;

            if ($row->status->existsAtPanel() && $remote === null) {
                /*
                 * A pending account the panel does not list is, while it is
                 * young, a build in progress: its row is written before the
                 * panel is asked, and this node's row can be reserved while
                 * the listing is being read. Neither is at the panel yet, and
                 * neither is drift. See PENDING_BUILD_WINDOW_MINUTES for how
                 * long that lasts and what happens after.
                 */
                if ($row->status === HostingAccountStatus::Pending && ! $this->pendingForTooLong($row)) {
                    continue;
                }

                $expected = ['status' => $row->status->value, 'node' => $node->slug];

                if ($row->status === HostingAccountStatus::Pending) {
                    $expected['pending_since'] = $row->updated_at?->toIso8601String();
                }

                $drifts[] = [
                    'provider' => $node->panel->value,
                    'resourceType' => self::RESOURCE,
                    'kind' => DriftKind::MissingAtProvider,
                    'providerReference' => $row->username,
                    'serviceId' => (string) ($row->service_id ?? ''),
                    'expected' => $expected,
                    'observed' => ['present' => false],
                    // The customer is paying for a website that is not being
                    // served, and they will find out before the platform does.
                    'severity' => DriftSeverity::Critical,
                ];

                continue;
            }

            if (! $row->status->existsAtPanel() && $remote !== null) {
                $drifts[] = [
                    'provider' => $node->panel->value,
                    'resourceType' => self::RESOURCE,
                    'kind' => DriftKind::OrphanAtProvider,
                    'providerReference' => $row->username,
                    'serviceId' => (string) ($row->service_id ?? ''),
                    'expected' => ['status' => $row->status->value],
                    'observed' => ['present' => true, 'node' => $node->slug],
                    // Terminated here and alive there: disk and a licence slot
                    // nobody is billing for, and somebody's data still on a
                    // machine the platform thinks is empty.
                    'severity' => DriftSeverity::Warning,
                ];

                continue;
            }

            if ($remote !== null && $this->suspensionDisagrees($row, $remote)) {
                $drifts[] = [
                    'provider' => $node->panel->value,
                    'resourceType' => self::RESOURCE,
                    'kind' => DriftKind::SuspensionMismatch,
                    'providerReference' => $row->username,
                    'serviceId' => (string) ($row->service_id ?? ''),
                    'expected' => ['suspended' => $row->status === HostingAccountStatus::Suspended],
                    'observed' => ['suspended' => $remote->suspended],
                    'severity' => DriftSeverity::Critical,
                ];
            }
        }

        return [...$drifts, ...$this->reportStrangers($node, $byUsername, $known)];
    }

    /**
     * Whether a pending account has been pending longer than a build takes.
     *
     * Measured from the row's last write (`updated_at`). ReserveHostingNodeCapacity
     * writes it when it reserves the slot and when it re-arms a failed
     * attempt; a retry of a pending row reuses it without writing. Any other
     * write to the row restarts the window, which can only make this later,
     * never earlier.
     */
    private function pendingForTooLong(HostingAccount $row): bool
    {
        $since = $row->updated_at;

        return $since === null || $since->lte(now()->subMinutes(self::PENDING_BUILD_WINDOW_MINUTES));
    }

    /**
     * Whether the platform and the panel disagree about the account being off.
     *
     * `pending` is excluded on purpose: an account still being created is
     * neither suspended nor not, and reporting one would make every build a
     * drift record for as long as it took.
     */
    private function suspensionDisagrees(HostingAccount $row, RemoteAccount $remote): bool
    {
        if ($row->status === HostingAccountStatus::Pending) {
            return false;
        }

        return ($row->status === HostingAccountStatus::Suspended) !== $remote->suspended;
    }

    /**
     * @param  array<string, RemoteAccount>  $byUsername
     * @param  list<string>  $known
     * @return list<array<string, mixed>>
     */
    private function reportStrangers(HostingNode $node, array $byUsername, array $known): array
    {
        $drifts = [];

        foreach ($byUsername as $username => $remote) {
            if (in_array($username, $known, strict: true)) {
                continue;
            }

            $drifts[] = [
                'provider' => $node->panel->value,
                'resourceType' => self::RESOURCE,
                'kind' => DriftKind::OrphanAtProvider,
                'providerReference' => $remote->username,
                'serviceId' => null,
                'expected' => ['known_to_platform' => false],
                'observed' => ['present' => true, 'node' => $node->slug, 'suspended' => $remote->suspended],
                'severity' => DriftSeverity::Warning,
            ];
        }

        return $drifts;
    }

    /**
     * The platform's capacity ledger against its own rows.
     *
     * Not a question about the panel at all, which is why it is cheap and why
     * it is here rather than nowhere: `account_count` is what the scheduler
     * places against, and it is incremented and decremented by hand in two
     * different actions. When it drifts, an over-counted node quietly refuses
     * accounts it could hold and an under-counted one oversells its disk —
     * and neither is visible from any screen.
     *
     * $node is the row locked for this comparison and $rows were read under
     * that lock, so the count and the rows are one moment's: an action that
     * moves the count takes the same lock (ReserveHostingNodeCapacity does)
     * and commits either before both reads or after this transaction.
     *
     * @param  list<HostingAccount>  $rows
     * @return list<array<string, mixed>>
     */
    private function checkCapacity(HostingNode $node, array $rows): array
    {
        $occupying = count(array_filter(
            $rows,
            static fn (HostingAccount $row): bool => $row->status->occupiesNodeCapacity(),
        ));

        if ($occupying === $node->account_count) {
            return [];
        }

        return [[
            'provider' => $node->panel->value,
            'resourceType' => self::NODE_RESOURCE,
            'kind' => DriftKind::SpecMismatch,
            'providerReference' => $node->slug,
            'serviceId' => null,
            'expected' => ['account_count' => $node->account_count],
            'observed' => ['accounts_occupying_capacity' => $occupying],
            'severity' => DriftSeverity::Warning,
        ]];
    }

    /**
     * Records a node's drifts in the order of their RecordDrift identity, and
     * returns how many there were.
     *
     * Each drift takes RecordDrift's transaction-scoped advisory lock, held
     * until this node's transaction ends, and the identity it is keyed by
     * names the panel type and the username but not the node. Two sweeps on
     * two nodes of one panel type (an operator's run beside the scheduled one
     * reaches that: each goes through the nodes in the order it read them, and
     * the second read its order after the first had stamped some) that met
     * the same two usernames in opposite orders each held one lock the other
     * wanted, and PostgreSQL failed one with a deadlock. Taken in one order
     * everywhere, a sweep that holds a lock waits only for ones after it, so
     * no two sweeps wait on each other. That holds for a sweep that holds no
     * lock from before its node: one called inside a transaction of its own
     * caller keeps every node's drift locks until that caller commits, and
     * this order says nothing about those. Nor about two identities whose
     * hashtext() is equal: they share one lock, and the order here is of the
     * identities, not of their hashes.
     *
     * @param  list<array<string, mixed>>  $drifts
     */
    private function recordInOrder(array $drifts): int
    {
        $identity = static fn (array $drift): string => implode('|', [
            $drift['provider'],
            $drift['resourceType'],
            $drift['kind']->value,
            $drift['providerReference'] ?? '',
            $drift['serviceId'] ?? '',
        ]);

        usort($drifts, static fn (array $a, array $b): int => strcmp($identity($a), $identity($b)));

        foreach ($drifts as $drift) {
            $this->drift->execute(...$drift);
        }

        return count($drifts);
    }

    /**
     * Nodes worth asking: the ones that are supposed to be answering.
     *
     * A node in maintenance is deliberately not asked. Its accounts are
     * expected to be in whatever state the work left them, and reporting that
     * as drift would fill an operator's queue with the consequences of their
     * own maintenance window.
     *
     * Least recently asked first — by the attempt, not by the last successful
     * comparison, so a node that could not be read waits its turn behind the
     * others instead of being asked first on every run.
     *
     * @return list<HostingNode>
     */
    private function reachableNodes(): array
    {
        /** @var list<HostingNode> $nodes */
        $nodes = HostingNode::query()
            ->whereIn('status', [HostingNodeStatus::Active->value, HostingNodeStatus::Draining->value])
            ->orderByRaw('reconcile_attempted_at asc nulls first')
            ->orderByRaw('reconciled_at asc nulls first')
            ->limit(max(1, (int) config('hosting.reconcile_batch', 25)))
            ->get()
            ->all();

        return $nodes;
    }
}
