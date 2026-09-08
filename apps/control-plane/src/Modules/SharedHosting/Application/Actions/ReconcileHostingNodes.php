<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

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
 *    first person to notice is them.
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

    public function __construct(
        private HostingProviderFactory $providers,
        private RecordDrift $drift,
    ) {}

    /**
     * @return array{nodes: int, accounts: int, drifts: int}
     */
    public function execute(): array
    {
        $nodes = 0;
        $accounts = 0;
        $drifts = 0;

        foreach ($this->reachableNodes() as $node) {
            try {
                $listed = $this->providers->for($node)->listAccounts($node);
            } catch (HostingProviderException) {
                /*
                 * A panel that will not answer is not drift. Nothing is
                 * concluded — a sweep that recorded "missing at the panel" for
                 * every account on a node whose API was down would report an
                 * outage as data loss, and bury the one real missing account
                 * in the middle of it.
                 */
                continue;
            }

            $nodes++;

            /** @var list<HostingAccount> $rows */
            $rows = HostingAccount::query()
                ->where('hosting_node_id', $node->getKey())
                ->get()
                ->all();

            $accounts += count($rows);
            $drifts += $this->compare($node, $rows, $listed);
            $drifts += $this->checkCapacity($node, $rows);

            $node->forceFill(['reconciled_at' => now()])->save();
        }

        return ['nodes' => $nodes, 'accounts' => $accounts, 'drifts' => $drifts];
    }

    /**
     * @param  list<HostingAccount>  $rows
     * @param  list<RemoteAccount>  $listed
     */
    private function compare(HostingNode $node, array $rows, array $listed): int
    {
        $drifts = 0;

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
                $this->drift->execute(
                    provider: $node->panel->value,
                    resourceType: self::RESOURCE,
                    kind: DriftKind::MissingAtProvider,
                    providerReference: $row->username,
                    serviceId: (string) ($row->service_id ?? ''),
                    expected: ['status' => $row->status->value, 'node' => $node->slug],
                    observed: ['present' => false],
                    // The customer is paying for a website that is not being
                    // served, and they will find out before the platform does.
                    severity: DriftSeverity::Critical,
                );

                $drifts++;

                continue;
            }

            if (! $row->status->existsAtPanel() && $remote !== null) {
                $this->drift->execute(
                    provider: $node->panel->value,
                    resourceType: self::RESOURCE,
                    kind: DriftKind::OrphanAtProvider,
                    providerReference: $row->username,
                    serviceId: (string) ($row->service_id ?? ''),
                    expected: ['status' => $row->status->value],
                    observed: ['present' => true, 'node' => $node->slug],
                    // Terminated here and alive there: disk and a licence slot
                    // nobody is billing for, and somebody's data still on a
                    // machine the platform thinks is empty.
                    severity: DriftSeverity::Warning,
                );

                $drifts++;

                continue;
            }

            if ($remote !== null && $this->suspensionDisagrees($row, $remote)) {
                $this->drift->execute(
                    provider: $node->panel->value,
                    resourceType: self::RESOURCE,
                    kind: DriftKind::SuspensionMismatch,
                    providerReference: $row->username,
                    serviceId: (string) ($row->service_id ?? ''),
                    expected: ['suspended' => $row->status === HostingAccountStatus::Suspended],
                    observed: ['suspended' => $remote->suspended],
                    severity: DriftSeverity::Critical,
                );

                $drifts++;
            }
        }

        return $drifts + $this->reportStrangers($node, $byUsername, $known);
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
     */
    private function reportStrangers(HostingNode $node, array $byUsername, array $known): int
    {
        $drifts = 0;

        foreach ($byUsername as $username => $remote) {
            if (in_array($username, $known, strict: true)) {
                continue;
            }

            $this->drift->execute(
                provider: $node->panel->value,
                resourceType: self::RESOURCE,
                kind: DriftKind::OrphanAtProvider,
                providerReference: $remote->username,
                expected: ['known_to_platform' => false],
                observed: ['present' => true, 'node' => $node->slug, 'suspended' => $remote->suspended],
                severity: DriftSeverity::Warning,
            );

            $drifts++;
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
     * @param  list<HostingAccount>  $rows
     */
    private function checkCapacity(HostingNode $node, array $rows): int
    {
        $occupying = count(array_filter(
            $rows,
            static fn (HostingAccount $row): bool => $row->status->occupiesNodeCapacity(),
        ));

        if ($occupying === $node->account_count) {
            return 0;
        }

        $this->drift->execute(
            provider: $node->panel->value,
            resourceType: self::NODE_RESOURCE,
            kind: DriftKind::SpecMismatch,
            providerReference: $node->slug,
            expected: ['account_count' => $node->account_count],
            observed: ['accounts_occupying_capacity' => $occupying],
            severity: DriftSeverity::Warning,
        );

        return 1;
    }

    /**
     * Nodes worth asking: the ones that are supposed to be answering.
     *
     * A node in maintenance is deliberately not asked. Its accounts are
     * expected to be in whatever state the work left them, and reporting that
     * as drift would fill an operator's queue with the consequences of their
     * own maintenance window.
     *
     * @return list<HostingNode>
     */
    private function reachableNodes(): array
    {
        /** @var list<HostingNode> $nodes */
        $nodes = HostingNode::query()
            ->whereIn('status', [HostingNodeStatus::Active->value, HostingNodeStatus::Draining->value])
            ->orderByRaw('reconciled_at asc nulls first')
            ->limit(max(1, (int) config('hosting.reconcile_batch', 25)))
            ->get()
            ->all();

        return $nodes;
    }
}
