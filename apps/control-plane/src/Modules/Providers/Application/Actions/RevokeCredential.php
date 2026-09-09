<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Exceptions\CredentialRefused;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;

/**
 * Withdraw a credential from everything that uses it.
 *
 * ---------------------------------------------------------------------------
 * What revoking does and does not do
 * ---------------------------------------------------------------------------
 *
 * It marks the reference revoked, so that nothing may try it and nothing may
 * serve with it, and it reassesses every provider that pointed at it so their
 * blockers say "credential revoked" from the next screen load. The row stays;
 * the foreign keys stay. A provider blocked on a revoked credential is a
 * provider whose screen can say which credential, when, by whom and why.
 *
 * It does not rotate anything, disable anything, or touch the secret backend.
 * The secret itself is the deployment controller's to remove; this records the
 * decision that it must not be used, which is the decision the platform can
 * enforce. And it does not switch off an Enabled provider — that is an
 * operator's call, for the reason AssessProvider gives: an automatic shutdown
 * on a credential event turns a key rotation into a halt in sales.
 */
final readonly class RevokeCredential
{
    public function __construct(
        private AssessProvider $assess,
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws CredentialRefused
     */
    public function execute(CredentialReference $credential, User $operator, string $reason): CredentialReference
    {
        if (trim($reason) === '') {
            throw CredentialRefused::withoutAReason($credential->name);
        }

        $revoked = $this->record->execute(
            act: function () use ($credential, $operator, $reason): CredentialReference {
                /** @var CredentialReference $locked */
                $locked = CredentialReference::query()
                    ->whereKey($credential->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->isRevoked()) {
                    throw CredentialRefused::alreadyRevoked($locked->name);
                }

                $locked->forceFill([
                    'state' => CredentialState::Revoked,
                    'revoked_at' => CarbonImmutable::now(),
                    'revoked_by' => $operator->getKey(),
                    'revoked_reason' => $reason,
                ])->save();

                return $locked;
            },
            describe: fn (CredentialReference $locked): AuditedAct => new AuditedAct(
                action: AuditAction::CredentialRevoked,
                subject: $locked,
                context: [
                    'credential' => $locked->name,
                    'environment' => $locked->environment->value,
                    'reason' => $reason,
                    'providers_affected' => $locked->providerInstances()->count(),
                    'servers_affected' => $locked->servers()->count(),
                    'operator' => $operator->getKey(),
                ],
            ),
        );

        // Outside the transaction: a failure to recompute a display column on
        // one provider must not undo the revocation of a credential somebody
        // has decided is compromised.
        $revoked->providerInstances()
            ->with(['server', 'credential', 'licence', 'capabilities'])
            ->each(fn (ProviderInstance $provider) => $this->assess->execute($provider));

        return $revoked;
    }
}
