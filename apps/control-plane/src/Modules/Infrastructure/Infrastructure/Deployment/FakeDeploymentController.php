<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Infrastructure\Deployment;

use Lynomia\Modules\Infrastructure\Domain\Contracts\DeploymentController;
use Lynomia\Modules\Infrastructure\Domain\DTOs\DeploymentOrder;
use Lynomia\Modules\Infrastructure\Domain\DTOs\DeploymentOutcome;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;

/**
 * A deployment controller that rehearses every outcome and touches nothing.
 *
 * The marker after `fake://` on the machine's management address decides
 * what happens, so a test or a rehearsal chooses its outcome by registering
 * the machine rather than by reaching into the controller:
 *
 *   connected            apply succeeds, verify observes every component present
 *   apply-fails          the playbook fails on a task — permanent
 *   apply-transient      the playbook fails on a connection — transient
 *   apply-timeout        the playbook outlives its deadline — INDETERMINATE
 *   verify-fails         apply succeeds; verification finds a component absent
 *   verify-timeout       apply succeeds; verification never answers
 *
 * Refuses to exist in production. A controller that reports success without
 * touching a machine is exactly the thing this phase exists to keep out of
 * a production build.
 */
final readonly class FakeDeploymentController implements DeploymentController
{
    public function __construct(
        private string $environment,
    ) {
        if ($this->environment === 'production') {
            throw DeploymentRefused::controllerRefused('The fake deployment controller is refused in production.');
        }
    }

    public function driver(): string
    {
        return 'fake';
    }

    public function apply(DeploymentOrder $order): DeploymentOutcome
    {
        $marker = $this->marker($order);
        $steps = [['name' => 'safety_gate', 'outcome' => 'passed'], ['name' => 'connect', 'outcome' => 'passed']];

        return match ($marker) {
            'apply-fails' => DeploymentOutcome::failed(
                FailureClass::Permanent,
                'The playbook failed on a task: package not found in the configured repository.',
                [...$steps, ['name' => 'playbook', 'outcome' => 'failed', 'detail' => 'role '.($order->roles[0] ?? 'common').' failed']],
            ),
            'apply-transient' => DeploymentOutcome::failed(
                FailureClass::Transient,
                'The host closed the connection during the run.',
                [['name' => 'safety_gate', 'outcome' => 'passed'], ['name' => 'connect', 'outcome' => 'failed', 'detail' => 'connection reset']],
            ),
            'apply-timeout' => DeploymentOutcome::indeterminate(
                sprintf('The playbook did not finish within %d seconds. The machine may be part-way through the change.', $order->timeoutSeconds),
                [...$steps, ['name' => 'playbook', 'outcome' => 'timed_out']],
            ),
            'network-failed', 'unavailable' => DeploymentOutcome::failed(
                FailureClass::Transient,
                'The host is unreachable on the management network.',
                [['name' => 'safety_gate', 'outcome' => 'passed'], ['name' => 'connect', 'outcome' => 'failed', 'detail' => 'no route to host']],
            ),
            default => DeploymentOutcome::succeeded([
                ...$steps,
                ...array_map(static fn (string $role): array => ['name' => 'role:'.$role, 'outcome' => 'passed'], $order->roles),
            ]),
        };
    }

    public function verify(DeploymentOrder $order): DeploymentOutcome
    {
        $marker = $this->marker($order);
        $components = array_keys($order->verifications);

        if ($marker === 'verify-timeout') {
            return DeploymentOutcome::indeterminate('Verification did not answer within the deadline.', [['name' => 'connect', 'outcome' => 'timed_out']]);
        }

        if ($marker === 'verify-fails') {
            $absent = $components[count($components) - 1] ?? 'common';

            return DeploymentOutcome::failed(
                FailureClass::Permanent,
                sprintf('%s is not present after the run.', $absent),
                [['name' => 'connect', 'outcome' => 'passed'], ['name' => 'verify:'.$absent, 'outcome' => 'failed', 'detail' => 'service not running']],
            );
        }

        if (in_array($marker, ['network-failed', 'unavailable'], strict: true)) {
            return DeploymentOutcome::failed(FailureClass::Transient, 'The host is unreachable on the management network.', [['name' => 'connect', 'outcome' => 'failed']]);
        }

        $facts = [];
        $steps = [['name' => 'connect', 'outcome' => 'passed']];

        foreach ($components as $component) {
            $facts['software.'.$component.'.present'] = 'true';
            $steps[] = ['name' => 'verify:'.$component, 'outcome' => 'passed'];
        }

        return DeploymentOutcome::succeeded($steps, $facts);
    }

    private function marker(DeploymentOrder $order): string
    {
        return str_starts_with($order->managementAddress, 'fake://')
            ? substr($order->managementAddress, strlen('fake://'))
            : 'connected';
    }
}
