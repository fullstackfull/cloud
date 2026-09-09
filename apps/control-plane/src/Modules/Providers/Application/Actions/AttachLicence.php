<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Providers\Domain\Exceptions\LicenceRefused;
use Lynomia\Modules\Providers\Infrastructure\Models\Licence;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;

/**
 * Say which licence a provider runs under, or that it no longer does.
 *
 * The same shape as attaching a credential, for the same reason: the
 * environment is checked here and not only when readiness is computed, so a
 * staging licence cannot sit on a production provider waiting for a check to
 * be relaxed. A licence the vendor has rejected is refused outright — pointing
 * something at it does not make it good.
 */
final readonly class AttachLicence
{
    public function __construct(
        private AssessProvider $assess,
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws LicenceRefused
     */
    public function toProvider(ProviderInstance $provider, Licence $licence, User $operator): ProviderInstance
    {
        if ($licence->state === LicenceState::Invalid) {
            throw LicenceRefused::invalid($licence->product);
        }

        if (! $licence->environment->satisfies($provider->environment)) {
            throw LicenceRefused::wrongEnvironment($provider->name, $provider->environment, $licence->environment);
        }

        $attached = $this->record->execute(
            act: function () use ($provider, $licence): ProviderInstance {
                $provider->forceFill(['licence_id' => $licence->getKey()])->save();

                return $provider;
            },
            describe: fn (): AuditedAct => new AuditedAct(
                action: AuditAction::LicenceAttached,
                subject: $licence,
                context: [
                    'product' => $licence->product,
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
        $previous = $provider->licence;

        $detached = $this->record->execute(
            act: function () use ($provider): ProviderInstance {
                $provider->forceFill(['licence_id' => null])->save();

                return $provider;
            },
            describe: fn (): ?AuditedAct => $previous === null ? null : new AuditedAct(
                action: AuditAction::LicenceDetached,
                subject: $previous,
                context: [
                    'product' => $previous->product,
                    'provider' => $provider->name,
                    'operator' => $operator->getKey(),
                ],
            ),
        );

        $this->assess->execute($detached->load(['server', 'credential', 'licence', 'capabilities']));

        return $detached;
    }
}
