<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Application\Actions;

use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Application\Actions\RecordDrift;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;

/**
 * Compares what the platform believes about a cluster's machines with what the
 * hypervisor reports, and records every disagreement.
 *
 * ---------------------------------------------------------------------------
 * Read-only, and it will stay that way
 * ---------------------------------------------------------------------------
 *
 * It calls exactly one method on the adapter — listVms(), a GET — and writes
 * only to the platform's own drift table. It never creates a machine the
 * provider is missing and never destroys one the platform does not know about.
 * Both would be a reconciler acting on an incomplete picture: an API that pages
 * badly, a node that is briefly unreachable, or a machine that is still being
 * built are all indistinguishable from drift at this level, and the remedy for
 * each is different. The platform records, alerts, and waits for a person.
 *
 * ---------------------------------------------------------------------------
 * What counts as drift
 * ---------------------------------------------------------------------------
 *
 *  - **Missing at the provider.** The platform has an active machine with a
 *    provider id and the hypervisor does not list it. Critical: a customer is
 *    paying for something that is not there.
 *  - **Orphan at the provider.** The hypervisor lists a machine the platform
 *    has no row for. A warning rather than critical, because the platform is
 *    not the only thing that may legitimately create a VM on a node an operator
 *    also uses — but it is unbilled and unmanaged until somebody looks.
 *  - **State mismatch.** Both agree the machine exists and disagree about
 *    whether it is running. Information, not an emergency: a customer may have
 *    shut a machine down from inside it.
 *
 * A machine still being built is deliberately not compared: it has no provider
 * id yet, and calling that drift would page an operator every time somebody
 * bought a server.
 */
final readonly class DetectVirtualMachineDrift
{
    public function __construct(
        private ComputeProviderFactory $providers,
        private RecordDrift $recordDrift,
    ) {}

    /**
     * @return int how many disagreements were recorded
     *
     * @throws ComputeProviderException
     */
    public function execute(ComputeCluster $cluster): int
    {
        $provider = $this->providers->for($cluster);
        $recorded = 0;

        /** @var list<ComputeNode> $nodes */
        $nodes = ComputeNode::query()
            ->where('cluster_id', $cluster->getKey())
            ->whereIn('status', [NodeStatus::Active->value, NodeStatus::Draining->value])
            ->get()
            ->all();

        foreach ($nodes as $node) {
            /** @var list<RemoteVmState> $remote */
            $remote = $provider->listVms($node->provider_name);

            $recorded += $this->compare($cluster, $node, $remote);
        }

        return $recorded;
    }

    /**
     * @param  list<RemoteVmState>  $remote
     */
    private function compare(ComputeCluster $cluster, ComputeNode $node, array $remote): int
    {
        $recorded = 0;

        /** @var array<string, RemoteVmState> $byProviderId */
        $byProviderId = [];

        foreach ($remote as $state) {
            $byProviderId[$state->providerId] = $state;
        }

        /** @var list<VirtualMachine> $machines */
        $machines = VirtualMachine::query()
            ->where('node_id', $node->getKey())
            ->whereNotNull('provider_id')
            ->get()
            ->all();

        $known = [];

        foreach ($machines as $machine) {
            $providerId = (string) $machine->provider_id;
            $known[$providerId] = true;

            if (! isset($byProviderId[$providerId])) {
                $this->recordDrift->execute(
                    provider: $cluster->driver->value,
                    resourceType: 'virtual_machine',
                    kind: DriftKind::MissingAtProvider,
                    providerReference: $providerId,
                    serviceId: $machine->service_id,
                    expected: ['hostname' => $machine->hostname, 'node' => $node->provider_name],
                    observed: null,
                    severity: DriftSeverity::Critical,
                );

                $recorded++;

                continue;
            }

            if ($byProviderId[$providerId]->powerState !== $machine->power_state) {
                $this->recordDrift->execute(
                    provider: $cluster->driver->value,
                    resourceType: 'virtual_machine',
                    kind: DriftKind::StateMismatch,
                    providerReference: $providerId,
                    serviceId: $machine->service_id,
                    expected: ['power_state' => $machine->power_state->value],
                    observed: ['power_state' => $byProviderId[$providerId]->powerState->value],
                    severity: DriftSeverity::Info,
                );

                $recorded++;
            }
        }

        foreach ($byProviderId as $providerId => $state) {
            if (isset($known[$providerId])) {
                continue;
            }

            $this->recordDrift->execute(
                provider: $cluster->driver->value,
                resourceType: 'virtual_machine',
                kind: DriftKind::OrphanAtProvider,
                providerReference: (string) $providerId,
                serviceId: null,
                expected: null,
                observed: ['node' => $node->provider_name, 'name' => $state->name],
                severity: DriftSeverity::Warning,
            );

            $recorded++;
        }

        return $recorded;
    }
}
