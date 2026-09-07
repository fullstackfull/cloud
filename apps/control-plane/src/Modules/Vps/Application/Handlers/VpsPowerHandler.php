<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Handlers;

use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\VmOperation;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\Vps\Domain\Enums\PowerAction;

/**
 * Everything the three power handlers do that is not choosing a method.
 *
 * Each concrete subclass exists only because the engine keys handlers by kind
 * and a handler declares exactly one; the work is identical apart from which
 * call the provider receives, and which action a given kind is allowed to
 * carry.
 *
 * Three things here are worth stating.
 *
 * **The action comes from the payload, not the kind.** `stop` and `shutdown`
 * are both ProvisioningJobKind::Stop, because that is the engine's vocabulary
 * and this module does not get to widen it. Reading the kind to decide which
 * provider call to make would collapse the two, and one of those directions
 * pulls the plug on a customer who asked politely. The kind is checked against
 * the action instead, so a payload that does not belong to this handler is
 * refused rather than approximated.
 *
 * **A timeout is a timeout.** The adapter is the only thing that saw the
 * transport, so its own verdict on whether the request may still be in flight
 * decides the classification. Indeterminate becomes FailureClass::Timeout,
 * which the engine neither retries nor compensates — it escalates. That is the
 * correct answer even for a reboot: a reboot the platform stopped waiting for
 * may be happening right now, and a retry is a second reboot landing on a
 * machine that has just come back up.
 *
 * **The believed state is written, and only the believed state.** The provider
 * returns a task that is still running; the machine is not actually stopped
 * yet. power_state records what the platform believes it asked for, exactly as
 * the create handler does, and reconciliation is what compares that with the
 * hypervisor. Writing "stopped" only after polling would leave the column
 * showing "running" for a machine the customer just turned off.
 */
abstract class VpsPowerHandler implements ProvisioningHandler
{
    public function __construct(
        private readonly ComputeProviderFactory $computeProviders,
        private readonly SecretRedactor $redactor,
    ) {}

    /**
     * Which actions this handler's kind may carry.
     *
     * @return non-empty-list<PowerAction>
     */
    abstract protected function permittedActions(): array;

    public function execute(ProvisioningJob $job): ProvisioningResult
    {
        /** @var array<string, mixed> $payload */
        $payload = $job->payload;

        $action = PowerAction::tryFrom((string) ($payload['power_action'] ?? ''));

        if ($action === null || ! in_array($action, $this->permittedActions(), strict: true)) {
            /*
             * Permanent, and refused rather than guessed at. A job whose
             * payload does not name an action this handler performs is a
             * wiring or migration fault, and the two plausible guesses — do
             * the hard thing, do the graceful thing — differ by whether the
             * customer loses their unflushed writes.
             */
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'vps.unknown_power_action',
                sprintf(
                    'The job payload names no power action this handler performs ("%s").',
                    (string) ($payload['power_action'] ?? ''),
                ),
            );
        }

        $machineId = (string) ($payload['virtual_machine_id'] ?? '');

        /*
         * Loaded by id, not scoped to a customer. That is correct here and
         * nowhere near the HTTP layer: the job row is what the engine trusts,
         * it was written by an action that had already scoped the machine
         * through the acting customer, and a worker has no acting customer to
         * scope by.
         */
        $machine = $machineId === '' ? null : VirtualMachine::query()->find($machineId);

        if ($machine === null) {
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'vps.unknown_machine',
                'The job names a virtual machine that no longer exists.',
                metadata: ['virtual_machine_id' => $machineId],
            );
        }

        $node = $machine->node()->first();
        $cluster = $machine->cluster()->first();

        if (! $machine->existsRemotely() || $node === null || $cluster === null) {
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'vps.not_provisioned',
                'The machine has no confirmed provider id, node or cluster, so there is nothing to send this to.',
                metadata: ['virtual_machine_id' => $machineId],
            );
        }

        try {
            $provider = $this->computeProviders->for($cluster);

            $operation = $this->call($provider, $action, $node->provider_name, (string) $machine->provider_id);
        } catch (ComputeProviderException $e) {
            return ProvisioningResult::failed(
                $e->isIndeterminate() ? FailureClass::Timeout : FailureClass::Transient,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        $machine->power_state = $this->believedStateAfter($action);
        $machine->save();

        return ProvisioningResult::succeeded(
            remoteJobId: $operation->taskId,
            providerReference: $operation->providerId,
            metadata: [
                'power_action' => $action->value,
                'node' => $node->provider_name,
                'virtual_machine_id' => (string) $machine->getKey(),
            ],
        );
    }

    /**
     * @throws ComputeProviderException
     */
    private function call(ComputeProvider $provider, PowerAction $action, string $nodeName, string $providerId): VmOperation
    {
        return match ($action) {
            PowerAction::Start => $provider->startVm($nodeName, $providerId),
            // Two calls, two meanings, and no default arm that could ever let
            // one stand in for the other.
            PowerAction::Stop => $provider->stopVm($nodeName, $providerId),
            PowerAction::Shutdown => $provider->shutdownVm($nodeName, $providerId),
            PowerAction::Reboot => $provider->rebootVm($nodeName, $providerId),
        };
    }

    private function believedStateAfter(PowerAction $action): PowerState
    {
        return match ($action) {
            PowerAction::Start, PowerAction::Reboot => PowerState::Running,
            PowerAction::Stop, PowerAction::Shutdown => PowerState::Stopped,
        };
    }
}
