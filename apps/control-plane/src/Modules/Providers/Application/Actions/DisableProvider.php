<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Domain\Events\ProviderReadinessChanged;
use Lynomia\Modules\Providers\Domain\Exceptions\ProviderRefused;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;

/**
 * Stop routing new work to a provider, and touch nothing that is already there.
 *
 * ---------------------------------------------------------------------------
 * The distinction this action exists to hold
 * ---------------------------------------------------------------------------
 *
 * Disabling is a sales decision, not an infrastructure one. A customer whose
 * VPS runs on the cluster behind this provider still owns that VPS, and it
 * must keep running, keep being backed up, keep being reachable and keep being
 * billed. What stops is Lynomia sending anything NEW here.
 *
 * So this action writes one column and does nothing else. It does not suspend
 * services, does not cancel subscriptions, does not touch the machine, and
 * does not cascade. That restraint is the feature: the obvious "tidy up"
 * version of this — deactivating what the provider serves — is how a
 * misclicked button becomes an outage for paying customers, and it would be
 * indistinguishable from a deliberate shutdown in the audit trail.
 *
 * The reverse direction is deliberately not symmetrical either. Enabling
 * demands a full readiness reassessment; disabling demands only a reason,
 * because making the safe direction expensive is how people end up leaving a
 * broken provider enabled.
 */
final readonly class DisableProvider
{
    public function __construct(
        private AssessProvider $assess,
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws ProviderRefused
     */
    public function execute(ProviderInstance $provider, User $operator, string $reason): ProviderInstance
    {
        if (trim($reason) === '') {
            throw ProviderRefused::withoutAReason($provider->name);
        }

        $disabled = $this->record->execute(
            act: function () use ($provider, $operator, $reason): ProviderInstance {
                /** @var ProviderInstance $locked */
                $locked = ProviderInstance::query()
                    ->whereKey($provider->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $locked->forceFill([
                    'state' => ProviderState::Disabled,
                    'disabled_at' => CarbonImmutable::now(),
                    'disabled_by' => $operator->getKey(),
                    'disabled_reason' => $reason,
                ])->save();

                event(new ProviderReadinessChanged(
                    $locked->id,
                    $locked->name,
                    $locked->category,
                    $locked->environment,
                    $locked->state,
                    $locked->readiness,
                    $locked->blocker,
                ));

                return $locked;
            },
            describe: fn (ProviderInstance $locked): AuditedAct => new AuditedAct(
                action: AuditAction::ProviderDisabled,
                subject: $locked,
                context: [
                    'provider' => $locked->name,
                    'category' => $locked->category->value,
                    'environment' => $locked->environment->value,
                    'reason' => $reason,
                    'operator' => $operator->getKey(),
                ],
            ),
        );

        /*
         * Reassessed after the state is written, so the readiness column
         * describes a disabled provider that is otherwise fine rather than
         * claiming it is ready to serve. Outside the transaction on purpose:
         * a failure to recompute a display column must not undo an operator's
         * decision to stop sending work somewhere.
         */
        $this->assess->execute($disabled);

        return $disabled;
    }
}
