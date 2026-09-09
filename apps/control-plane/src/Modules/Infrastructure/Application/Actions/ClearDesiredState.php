<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DesiredState;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;

/**
 * Withdraw the intention. Nothing is uninstalled: the machine keeps what it
 * has, and simply has no target any more. Standing approvals for its plans
 * are revoked, because they approved a plan towards a state nobody wants.
 */
final readonly class ClearDesiredState
{
    public function __construct(
        private RevokeDeploymentApproval $revoke,
        private RecordActAtomically $record,
    ) {}

    public function execute(ManagedServer $server, User $operator, string $reason): void
    {
        $state = DesiredState::query()->where('managed_server_id', $server->getKey())->first()
            ?? throw DeploymentRefused::noDesiredState($server->name);

        $this->record->execute(
            act: function () use ($server, $state, $operator, $reason): void {
                foreach ($server->plans()->get() as $plan) {
                    $approval = $plan->standingApproval();

                    if ($approval !== null) {
                        $this->revoke->execute($approval, $operator, 'Desired state cleared: '.$reason, automatic: true);
                    }
                }

                $state->delete();
            },
            describe: fn (): AuditedAct => new AuditedAct(
                action: AuditAction::DesiredStateCleared,
                subject: $server,
                context: ['server' => $server->name, 'reason' => $reason, 'operator' => $operator->getKey()],
            ),
        );
    }
}
