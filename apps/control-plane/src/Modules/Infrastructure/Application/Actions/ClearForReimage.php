<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\ClassificationRefused;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;

/**
 * Clear one machine for one piece of destructive work.
 *
 * Separate from the classification on purpose. The class is an operator's
 * standing decision about a machine — this box in this rack may be rebuilt.
 * The clearance is their decision about today: it is added for a scheduled
 * piece of work and taken away afterwards, which is exactly the discipline the
 * Ansible inventories describe and none of their example hosts sets.
 *
 * A machine permanently classified reimage_allowed with allow_reimage
 * permanently true is not guarded. It is a default with two columns.
 */
final readonly class ClearForReimage
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws ClassificationRefused
     */
    public function execute(
        ManagedServer $server,
        User $operator,
        string $reason,
        string $typedName,
    ): ManagedServer {
        if ($server->safety_class !== SafetyClass::ReimageAllowed) {
            throw ClassificationRefused::clearanceNeedsTheClass($server->name, $server->safety_class);
        }

        if ($typedName !== $server->name) {
            throw ClassificationRefused::withoutTypingTheName($server->name);
        }

        if (trim($reason) === '') {
            throw ClassificationRefused::withoutAReason($server->name);
        }

        return $this->record->execute(
            act: function () use ($server, $reason): ManagedServer {
                /** @var ManagedServer $locked */
                $locked = ManagedServer::query()
                    ->whereKey($server->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // Re-checked under the lock: the classification could have been
                // lowered between the check above and here, and the database
                // constraint would then refuse the write with a message about a
                // constraint rather than about safety.
                if ($locked->safety_class !== SafetyClass::ReimageAllowed) {
                    throw ClassificationRefused::clearanceNeedsTheClass($locked->name, $locked->safety_class);
                }

                $locked->forceFill([
                    'allow_reimage' => true,
                    'safety_reason' => $reason,
                ])->save();

                return $locked;
            },
            describe: fn (ManagedServer $locked): AuditedAct => new AuditedAct(
                action: AuditAction::ServerReimageCleared,
                subject: $locked,
                context: [
                    'server' => $locked->name,
                    'reason' => $reason,
                    'operator' => $operator->getKey(),
                ],
            ),
        );
    }
}
