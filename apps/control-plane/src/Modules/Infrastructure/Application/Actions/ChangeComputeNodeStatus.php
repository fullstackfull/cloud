<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * An operator says whether a hypervisor node may take customers.
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 *
 * SyncClusterInventory records a node it discovers in `maintenance`, and says
 * why: discovery is not authorisation, and a node that answers a GET has not
 * necessarily been cabled, patched or put into monitoring. The other half —
 * a person saying it has — did not exist. No route, command or action moved
 * a node to `active`, `node.maintenance` was held by two roles and asked for
 * by nothing, and every node on an estate an operator built stayed out of
 * placement for good (F-02: the platform could not be brought into a sellable
 * state by an operator).
 *
 * ---------------------------------------------------------------------------
 * What a person may say
 * ---------------------------------------------------------------------------
 *
 * `active`, `draining` and `maintenance` — the operator's own states. Not
 * `offline`. No code in this build writes NodeStatus::Offline — the reconcile
 * sweep records a node that stopped answering in `is_healthy`, not in the
 * status — and nothing treats it differently from `maintenance` except that
 * it holds no workloads; allowing it here would add a state that only this
 * route could ever enter or leave, for no behaviour. Nothing here makes an
 * unhealthy node schedulable either: placement asks for an active node that
 * is also healthy, and health is the reconcile sweep's.
 *
 * The reason is required and audited with the move, because "who put this
 * node into service, and on what grounds" is the question asked the day a
 * customer's machine lands on a box that was not ready.
 */
final readonly class ChangeComputeNodeStatus
{
    /** @var list<NodeStatus> */
    public const array SETTABLE = [NodeStatus::Active, NodeStatus::Draining, NodeStatus::Maintenance];

    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(ComputeNode $node, NodeStatus $to, string $reason, User $operator): ComputeNode
    {
        if (! in_array($to, self::SETTABLE, true)) {
            throw new \InvalidArgumentException(sprintf('A node is not set to "%s" by hand.', $to->value));
        }

        $from = $node->status;

        return $this->record->execute(
            act: function () use ($node, $to): ComputeNode {
                $node->forceFill(['status' => $to])->save();

                return $node->refresh();
            },
            describe: fn (ComputeNode $changed): AuditedAct => new AuditedAct(
                action: AuditAction::ComputeNodeStatusChanged,
                subject: $changed,
                context: [
                    'node' => $changed->provider_name,
                    'cluster_id' => (string) $changed->cluster_id,
                    'from' => $from->value,
                    'to' => $to->value,
                    'reason' => $reason,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }
}
