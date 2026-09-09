<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Providers\Domain\Exceptions\LicenceRefused;
use Lynomia\Modules\Providers\Infrastructure\Models\Licence;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;

/**
 * Record that the vendor rejected a licence.
 *
 * The one override of the calendar an operator has, and it only goes one way:
 * a licence whose dates say Active can be declared Invalid, and nothing an
 * operator types can declare an expired one Active. Every provider under it
 * is reassessed and reports a licence blocker carrying the reason. Nothing is
 * switched off — the panel behind the provider may well still be serving,
 * and whether to stop selling onto it is the operator's next decision, not
 * this action's side effect.
 */
final readonly class InvalidateLicence
{
    public function __construct(
        private AssessProvider $assess,
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws LicenceRefused
     */
    public function execute(Licence $licence, User $operator, string $reason): Licence
    {
        if (trim($reason) === '') {
            throw LicenceRefused::withoutAReason($licence->product);
        }

        $invalidated = $this->record->execute(
            act: function () use ($licence, $operator, $reason): Licence {
                /** @var Licence $locked */
                $locked = Licence::query()->whereKey($licence->getKey())->lockForUpdate()->firstOrFail();

                $locked->forceFill([
                    'state' => LicenceState::Invalid,
                    'state_changed_at' => CarbonImmutable::now(),
                    'invalidated_at' => CarbonImmutable::now(),
                    'invalidated_by' => $operator->getKey(),
                    'invalidated_reason' => $reason,
                ])->save();

                return $locked;
            },
            describe: fn (Licence $locked): AuditedAct => new AuditedAct(
                action: AuditAction::LicenceInvalidated,
                subject: $locked,
                context: [
                    'product' => $locked->product,
                    'environment' => $locked->environment->value,
                    'reason' => $reason,
                    'providers_affected' => $locked->providerInstances()->count(),
                    'operator' => $operator->getKey(),
                ],
            ),
        );

        $invalidated->providerInstances()
            ->with(['server', 'credential', 'licence', 'capabilities'])
            ->each(fn (ProviderInstance $provider) => $this->assess->execute($provider));

        return $invalidated;
    }
}
