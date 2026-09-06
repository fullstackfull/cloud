<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\SharedHosting\Domain\DTOs\HostingCapacityReservation;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\PlacementRejectionReason;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingNodeUnlicensedException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingUsernameConflictException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\NodeAtCapacityException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;

/**
 * Commits one slot on a node to one account.
 *
 * This is the only place hosting_nodes.account_count goes up, and it is the
 * point at which a placement stops being an opinion and becomes a promise.
 *
 * The re-check under the lock is the entire reason this action exists rather
 * than an increment at the call site. Scoring deliberately reads without a
 * lock — holding one across every node in the fleet would serialise every
 * order the platform takes — so two workers can both choose the same node in
 * the same millisecond, and both are right at the moment they choose. The lock
 * makes them take turns, and the second one re-reads the row it is about to
 * write and discovers the world has moved. Without that re-read the increment
 * is a lost update: both commit, the node passes its ceiling, and on a shared
 * machine the bill for that arrives as every site on it slowing down at once.
 *
 * The pending account row is created in the SAME transaction as the increment,
 * and that pairing is what makes a retried job safe. The unique index on
 * (hosting_node_id, username) is the idempotency key: a worker that died after
 * the panel accepted a create comes back, finds its own pending row, and does
 * not commit a second slot. Without it the node loses a slot permanently and
 * silently on every retry — and retries are most frequent exactly when the
 * fleet is already under strain.
 */
final readonly class ReserveHostingNodeCapacity
{
    /**
     * @param  string|null  $serviceId  The service the account belongs to, as a string id: services
     *                                  are owned by another module and this one must not depend on
     *                                  it to take a slot.
     *
     * @throws NodeAtCapacityException
     * @throws HostingNodeUnlicensedException
     * @throws HostingUsernameConflictException
     */
    public function execute(
        HostingNode $node,
        string $username,
        string $primaryDomain,
        string $customerId,
        ?HostingPackage $package = null,
        ?string $serviceId = null,
    ): HostingAccount {
        return $this->reserve($node, $username, $primaryDomain, $customerId, $package, $serviceId)->account;
    }

    /**
     * The same reservation, plus whether THIS attempt is the one that took the
     * slot.
     *
     * A caller that has to compensate needs both. The account row says what was
     * reserved; the flag says whether this attempt may give it back — see
     * {@see HostingCapacityReservation}. A handler that released capacity for a
     * row an earlier attempt had already carried to the panel would be
     * releasing after a timeout by another route.
     *
     * @throws NodeAtCapacityException
     * @throws HostingNodeUnlicensedException
     * @throws HostingUsernameConflictException
     */
    public function reserve(
        HostingNode $node,
        string $username,
        string $primaryDomain,
        string $customerId,
        ?HostingPackage $package = null,
        ?string $serviceId = null,
    ): HostingCapacityReservation {
        return DB::transaction(function () use (
            $node, $username, $primaryDomain, $customerId, $package, $serviceId
        ): HostingCapacityReservation {
            /*
             * Re-read under a row lock. The caller's copy was fetched during
             * scoring and is stale by definition: everything this action
             * guards against happened between then and now.
             */
            /** @var HostingNode $locked */
            $locked = HostingNode::query()->lockForUpdate()->findOrFail($node->getKey());

            $existing = HostingAccount::query()
                ->where('hosting_node_id', $locked->getKey())
                ->where('username', $username)
                ->first();

            /*
             * The row is only this job's own earlier attempt when it belongs
             * to the same customer. A row carrying the same name for somebody
             * else is a collision, not a retry — and every branch below treats
             * what it finds as "the account this job already holds": it re-arms
             * the row to pending and the handler then marks it active, leaving
             * a live account whose customer_id, service_id and primary_domain
             * still name the other tenant. One panel account, two customers'
             * records, and whichever of them acts on it first — a portal
             * listing, an SSO session, a suspend, a terminate — crosses the
             * boundary.
             *
             * Refused outright rather than renamed: the name is unique per
             * node at the panel as well as in this table, so there is nothing
             * this node can serve under it.
             */
            if ($existing !== null && (string) $existing->customer_id !== $customerId) {
                throw HostingUsernameConflictException::forUsername(
                    (string) $locked->getKey(),
                    $locked->hostname,
                    $username,
                );
            }

            if ($existing !== null && $existing->status->occupiesNodeCapacity()) {
                // This job already holds its slot. Returning the row rather
                // than incrementing again is what makes a retry safe; the
                // caller carries on and asks the panel to create the account,
                // which is itself idempotent by name.
                //
                // Reported as NOT taken by this attempt: an earlier one has
                // already been to the panel under this name, so this attempt
                // is not entitled to give the slot back on a refusal.
                return new HostingCapacityReservation($existing, slotTakenNow: false);
            }

            /*
             * The licence is re-checked here and not only in the scheduler.
             * Placement happens when the order is paid and this runs when a
             * worker picks the job up, which can be minutes later — and a
             * licence expires on a date, not on an event. Creating an account
             * on a node whose licence lapsed in between means selling hosting
             * that stops serving when the panel's grace period ends.
             */
            if (! $locked->isLicensed()) {
                throw HostingNodeUnlicensedException::forNode(
                    (string) $locked->getKey(),
                    $locked->hostname,
                    $locked->licence_status,
                );
            }

            if (! $locked->status->acceptsNewAccounts() || ! $locked->accepts_new_accounts) {
                throw NodeAtCapacityException::forNode(
                    (string) $locked->getKey(),
                    $locked->hostname,
                    PlacementRejectionReason::NodeNotAccepting,
                    sprintf('the node is %s and no longer takes new accounts', $locked->status->value),
                );
            }

            if ($locked->freeAccountSlots() < 1) {
                throw NodeAtCapacityException::forNode(
                    (string) $locked->getKey(),
                    $locked->hostname,
                    PlacementRejectionReason::AccountLimitReached,
                    sprintf('the node holds %d accounts and its ceiling is %d', $locked->account_count, $locked->accountLimit()),
                );
            }

            $diskPercent = $locked->diskUsedPercent();
            $maxDisk = max(1, min(100, (int) config('hosting.scheduler.max_disk_used_percent', 75)));

            if ($diskPercent !== null && $diskPercent >= $maxDisk) {
                // Re-applied under the lock for the same reason as the count:
                // a health sync between placement and reservation can have
                // moved the node over the line, and disk is the threshold whose
                // breach takes every site on the machine down together.
                throw NodeAtCapacityException::forNode(
                    (string) $locked->getKey(),
                    $locked->hostname,
                    PlacementRejectionReason::DiskThresholdExceeded,
                    sprintf('disk is %.1f%% used and the threshold is %d%%', $diskPercent, $maxDisk),
                );
            }

            $locked->account_count += 1;
            $locked->save();

            /*
             * Written inside the same transaction as the counter, so the
             * commitment and the thing it was committed for can never
             * disagree. The unique index on (hosting_node_id, username) is the
             * real guard: two workers that both passed the checks above
             * serialise here, and the loser's transaction rolls back with its
             * increment.
             *
             * Pending, not active. The panel has not been asked yet; the row
             * exists so that a create whose answer never arrives leaves
             * something to reconcile against instead of an account on a node
             * that nothing in the platform knows about.
             */
            if ($existing !== null) {
                /*
                 * A row that exists but holds no capacity is a previous
                 * attempt that failed outright and handed its slot back — see
                 * releaseFor(). The retry has to re-commit a slot for it
                 * rather than carry on without one: an account that went
                 * active against a count that was never incremented is a node
                 * the scheduler oversubscribes by exactly one for every such
                 * retry.
                 *
                 * The audit row is reused rather than replaced, so the account
                 * keeps one identity across every attempt — and so the unique
                 * index on (hosting_node_id, username) stays the idempotency
                 * key it is here to be.
                 */
                $existing->forceFill(['status' => HostingAccountStatus::Pending])->save();

                return new HostingCapacityReservation($existing, slotTakenNow: true);
            }

            return new HostingCapacityReservation(
                HostingAccount::query()->create([
                    'hosting_node_id' => $locked->getKey(),
                    'hosting_package_id' => $package?->getKey(),
                    'customer_id' => $customerId,
                    'service_id' => $serviceId,
                    'username' => $username,
                    'primary_domain' => $primaryDomain,
                    'status' => HostingAccountStatus::Pending,
                ]),
                slotTakenNow: true,
            );
        });
    }

    /**
     * Move one account out of service and give its slot back, atomically.
     *
     * The status change and the decrement are one transaction under the node's
     * row lock because they are one fact. Written separately — mark the row,
     * then release — a process that died in between would leave a node one
     * slot short for the rest of its life, and the retry would find a status
     * that already says "released" and hand nothing back. Slots lost that way
     * are invisible: the node quietly stops accepting accounts while its disk
     * says it is half empty.
     *
     * Idempotent by the same mechanism. The decrement happens only while the
     * row still holds capacity, so calling this twice for one account releases
     * exactly one slot.
     *
     * The caller decides WHETHER this is safe; this method only decides that it
     * happens once. Nothing may be released after an indeterminate answer from
     * the panel — the account may exist, and its files are still on the disk.
     *
     * @param  array<string, mixed>  $extra  Further columns to write in the same transaction,
     *                                       such as terminated_at.
     */
    public function releaseFor(
        HostingAccount $account,
        HostingAccountStatus $status,
        array $extra = [],
    ): HostingAccount {
        return DB::transaction(function () use ($account, $status, $extra): HostingAccount {
            /** @var HostingNode $locked */
            $locked = HostingNode::query()->lockForUpdate()->findOrFail($account->hosting_node_id);

            /*
             * Re-read under the node's lock rather than trusting the caller's
             * copy: a dunning run and an operator can both be holding a stale
             * instance of the same account, and only the row says whether its
             * slot is still committed.
             */
            $current = HostingAccount::query()->lockForUpdate()->find($account->getKey());

            $holdsCapacity = ($current ?? $account)->status->occupiesNodeCapacity();

            if ($holdsCapacity && ! $status->occupiesNodeCapacity()) {
                // Floored at zero. A count that went negative would hand out
                // slots the node does not have, and the arithmetic error would
                // be invisible until the machine was oversubscribed.
                $locked->account_count = max(0, $locked->account_count - 1);
                $locked->save();
            }

            $account->forceFill([...$extra, 'status' => $status])->save();

            return $account;
        });
    }

    /**
     * Give a slot back.
     *
     * Called only when the account is genuinely gone from the panel. It is not
     * called on suspension: a suspended account still has its files, mail and
     * databases on the disk, which is exactly why suspension can be undone, and
     * releasing its slot would let the scheduler oversubscribe the node by the
     * number of accounts sitting out a billing dispute.
     */
    public function release(HostingNode $node): HostingNode
    {
        return DB::transaction(function () use ($node): HostingNode {
            /** @var HostingNode $locked */
            $locked = HostingNode::query()->lockForUpdate()->findOrFail($node->getKey());

            // Floored at zero. A count that went negative would hand out slots
            // the node does not have, and the arithmetic error would be
            // invisible until the machine was oversubscribed.
            $locked->account_count = max(0, $locked->account_count - 1);
            $locked->save();

            return $locked;
        });
    }
}
