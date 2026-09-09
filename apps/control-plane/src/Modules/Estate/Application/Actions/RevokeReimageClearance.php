<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Estate\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Take back a clearance once the work it was for is done.
 *
 * Needs no confirmation and no reason, because withdrawing permission is never
 * the dangerous direction and asking for ceremony here would make operators
 * leave clearances standing rather than tidy them away. That is the failure
 * this action exists to make easy to avoid.
 */
final readonly class RevokeReimageClearance
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(ManagedServer $server, User $operator): ManagedServer
    {
        return $this->record->execute(
            act: function () use ($server): ManagedServer {
                /** @var ManagedServer $locked */
                $locked = ManagedServer::query()
                    ->whereKey($server->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $locked->forceFill(['allow_reimage' => false])->save();

                return $locked;
            },
            describe: fn (ManagedServer $locked): AuditedAct => new AuditedAct(
                action: AuditAction::ServerReimageClearanceRevoked,
                subject: $locked,
                context: [
                    'server' => $locked->name,
                    'operator' => $operator->getKey(),
                ],
            ),
        );
    }
}
