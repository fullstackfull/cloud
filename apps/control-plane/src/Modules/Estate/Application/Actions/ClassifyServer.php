<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Estate\Domain\Enums\SafetyClass;
use Lynomia\Modules\Estate\Domain\Exceptions\ClassificationRefused;
use Lynomia\Modules\Estate\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Change what an operator has agreed we may do to a machine.
 *
 * ---------------------------------------------------------------------------
 * Why this is its own action, with its own permission and its own audit row
 * ---------------------------------------------------------------------------
 *
 * Because it is the act that decides what every other act may do. Everything
 * else in this module asks the classification; this is the only thing that sets
 * it, and an investigation into a machine that should not have been wiped reads
 * this row first.
 *
 * Four things are required and none of them is optional for convenience:
 *
 *   - one rung at a time upward, so a machine cannot go from untouched to
 *     wipeable without the read-only look in between;
 *   - the machine's own name, typed, for the destructive rung — a confirmation
 *     that is merely `true` is one the wrong browser tab can supply;
 *   - a reason, in the operator's words, because "why is this reimageable"
 *     is the question somebody asks three weeks later;
 *   - the row taken under a lock, so two operators cannot each read
 *     configuration_allowed and each write a different next rung.
 */
final readonly class ClassifyServer
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws ClassificationRefused
     */
    public function execute(
        ManagedServer $server,
        SafetyClass $to,
        User $operator,
        string $reason,
        ?string $typedName = null,
    ): ManagedServer {
        if (trim($reason) === '') {
            throw ClassificationRefused::withoutAReason($server->name);
        }

        // Typed before anything is locked, so the cheapest refusal happens
        // first and an operator who mistyped has not held a row meanwhile.
        if ($to->isDestructive() && $typedName !== $server->name) {
            throw ClassificationRefused::withoutTypingTheName($server->name);
        }

        return $this->record->execute(
            act: function () use ($server, $to, $operator, $reason): ManagedServer {
                /** @var ManagedServer $locked */
                $locked = ManagedServer::query()
                    ->whereKey($server->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $from = $locked->safety_class;

                if (! $from->mayBecome($to)) {
                    throw ClassificationRefused::notOneRung($locked->name, $from, $to);
                }

                $locked->forceFill([
                    'safety_class' => $to,
                    // Lowering the classification withdraws the clearance with
                    // it. Leaving allow_reimage set on a machine somebody has
                    // just decided to touch less is the exact combination the
                    // database CHECK constraint refuses, and doing it here as
                    // well means the refusal never has to happen.
                    'allow_reimage' => $to === SafetyClass::ReimageAllowed ? $locked->allow_reimage : false,
                    'safety_reason' => $reason,
                    'safety_changed_by' => $operator->getKey(),
                    'safety_changed_at' => CarbonImmutable::now(),
                ])->save();

                return $locked;
            },
            describe: fn (ManagedServer $locked): AuditedAct => new AuditedAct(
                action: AuditAction::ServerSafetyChanged,
                subject: $locked,
                context: [
                    'server' => $locked->name,
                    'to' => $to->value,
                    'reason' => $reason,
                    'operator' => $operator->getKey(),
                ],
            ),
        );
    }
}
