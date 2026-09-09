<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Providers\Domain\Contracts\SecretResolver;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Exceptions\CredentialRefused;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;

/**
 * Record that the secret behind a reference has been changed.
 *
 * The platform did not change it and cannot see that it changed; the operator
 * is asserting that the value on the deployment controller is new. What follows
 * from that is the part the platform can enforce: whatever was proven about the
 * old value is no longer known about the new one, so the state drops back to
 * Configured (or Missing, if the controller does not yet have the new value)
 * and every provider using it is reassessed — which stops any of them being
 * ReadyForProduction on the strength of a test that ran against a secret that
 * no longer exists.
 */
final readonly class MarkCredentialRotated
{
    public function __construct(
        private SecretResolver $secrets,
        private AssessProvider $assess,
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws CredentialRefused
     */
    public function execute(CredentialReference $credential, User $operator, ?CarbonImmutable $nextRotation = null): CredentialReference
    {
        if ($credential->isRevoked()) {
            throw CredentialRefused::revoked($credential->name);
        }

        $rotated = $this->record->execute(
            act: function () use ($credential, $nextRotation): CredentialReference {
                /** @var CredentialReference $locked */
                $locked = CredentialReference::query()
                    ->whereKey($credential->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $present = $this->secrets->exists($locked->backend, $locked->backend_reference);

                $locked->forceFill([
                    'state' => $present ? CredentialState::Configured : CredentialState::Missing,
                    'rotated_at' => CarbonImmutable::now(),
                    'last_tested_at' => null,
                    'rotates_at' => $nextRotation ?? $locked->rotates_at,
                ])->save();

                return $locked;
            },
            describe: fn (CredentialReference $locked): AuditedAct => new AuditedAct(
                action: AuditAction::CredentialRotated,
                subject: $locked,
                context: [
                    'credential' => $locked->name,
                    'environment' => $locked->environment->value,
                    'state' => $locked->state->value,
                    'operator' => $operator->getKey(),
                ],
            ),
        );

        $rotated->providerInstances()
            ->with(['server', 'credential', 'licence', 'capabilities'])
            ->each(fn (ProviderInstance $provider) => $this->assess->execute($provider));

        return $rotated;
    }
}
