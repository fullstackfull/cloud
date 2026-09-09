<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Domain\Exceptions\CredentialRefused;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;

/**
 * Point a provider account or a machine at the credential it authenticates
 * with, or stop doing so.
 *
 * ---------------------------------------------------------------------------
 * Environment is checked at attachment, not only at use
 * ---------------------------------------------------------------------------
 *
 * The resolver already refuses to hand a staging secret to a production test,
 * so a mismatched attachment would be harmless today. It is refused anyway,
 * because "harmless today" is how a staging token comes to sit on a production
 * provider until the day somebody relaxes the check at the point of use. Two
 * guards that fail independently are the design.
 *
 * Attaching touches nothing at the provider or on the machine. It changes what
 * the next connection test will try, and it changes the readiness verdict,
 * which is recomputed immediately so the screen that offered the attachment
 * shows what it changed.
 */
final readonly class AttachCredential
{
    public function __construct(
        private AssessProvider $assess,
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws CredentialRefused
     */
    public function toProvider(ProviderInstance $provider, CredentialReference $credential, User $operator): ProviderInstance
    {
        $this->guard($credential, $provider->environment);

        $attached = $this->record->execute(
            act: function () use ($provider, $credential): ProviderInstance {
                $provider->forceFill(['credential_reference_id' => $credential->getKey()])->save();

                return $provider;
            },
            describe: fn (): AuditedAct => new AuditedAct(
                action: AuditAction::CredentialAttached,
                subject: $credential,
                context: [
                    'credential' => $credential->name,
                    'provider' => $provider->name,
                    'environment' => $provider->environment->value,
                    'operator' => $operator->getKey(),
                ],
            ),
        );

        $this->assess->execute($attached->load(['server', 'credential', 'licence', 'capabilities']));

        return $attached;
    }

    public function fromProvider(ProviderInstance $provider, User $operator): ProviderInstance
    {
        $previous = $provider->credential;

        $detached = $this->record->execute(
            act: function () use ($provider): ProviderInstance {
                $provider->forceFill(['credential_reference_id' => null])->save();

                return $provider;
            },
            describe: fn (): ?AuditedAct => $previous === null ? null : new AuditedAct(
                action: AuditAction::CredentialDetached,
                subject: $previous,
                context: [
                    'credential' => $previous->name,
                    'provider' => $provider->name,
                    'operator' => $operator->getKey(),
                ],
            ),
        );

        $this->assess->execute($detached->load(['server', 'credential', 'licence', 'capabilities']));

        return $detached;
    }

    /**
     * @throws CredentialRefused
     */
    public function toServer(ManagedServer $server, CredentialReference $credential, User $operator): ManagedServer
    {
        // No safety-gate check, deliberately. Attaching a credential opens no
        // socket and changes nothing on the machine; it is the connection
        // test that is gated, and it stays gated whatever is attached.
        $this->guard($credential, $server->environment);

        return $this->record->execute(
            act: function () use ($server, $credential): ManagedServer {
                $server->forceFill(['credential_reference_id' => $credential->getKey()])->save();

                return $server;
            },
            describe: fn (): AuditedAct => new AuditedAct(
                action: AuditAction::CredentialAttached,
                subject: $credential,
                context: [
                    'credential' => $credential->name,
                    'server' => $server->name,
                    'environment' => $server->environment->value,
                    'operator' => $operator->getKey(),
                ],
            ),
        );
    }

    public function fromServer(ManagedServer $server, User $operator): ManagedServer
    {
        $previous = $server->credential;

        return $this->record->execute(
            act: function () use ($server): ManagedServer {
                $server->forceFill(['credential_reference_id' => null])->save();

                return $server;
            },
            describe: fn (): ?AuditedAct => $previous === null ? null : new AuditedAct(
                action: AuditAction::CredentialDetached,
                subject: $previous,
                context: [
                    'credential' => $previous->name,
                    'server' => $server->name,
                    'operator' => $operator->getKey(),
                ],
            ),
        );
    }

    private function guard(CredentialReference $credential, DeploymentEnvironment $target): void
    {
        if ($credential->isRevoked()) {
            throw CredentialRefused::revoked($credential->name);
        }

        if (! $credential->environment->satisfies($target)) {
            throw CredentialRefused::wrongEnvironment($credential->name, $credential->environment, $target);
        }
    }
}
