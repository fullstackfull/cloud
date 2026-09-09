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
 * Record that a licence has been renewed.
 *
 * A renewal is a new fact from the vendor, so it clears an invalidation: the
 * operator who marked the licence invalid was recording that the old term
 * failed, and the new term has not. The state is recomputed from the new
 * dates, and every provider under the licence is reassessed — which is how an
 * expired licence stops blocking the moment somebody records the renewal
 * rather than at the next nightly sweep.
 */
final readonly class RenewLicence
{
    public function __construct(
        private AssessProvider $assess,
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws LicenceRefused
     */
    public function execute(
        Licence $licence,
        User $operator,
        CarbonImmutable $expiresOn,
        ?CarbonImmutable $renewsOn = null,
        ?string $externalReference = null,
    ): Licence {
        if (! $expiresOn->startOfDay()->isAfter(CarbonImmutable::now()->startOfDay())) {
            throw LicenceRefused::renewalInThePast($licence->product);
        }

        $renewed = $this->record->execute(
            act: function () use ($licence, $expiresOn, $renewsOn, $externalReference): Licence {
                /** @var Licence $locked */
                $locked = Licence::query()->whereKey($licence->getKey())->lockForUpdate()->firstOrFail();

                $from = $locked->state;

                $locked->forceFill([
                    'expires_on' => $expiresOn,
                    'renews_on' => $renewsOn ?? $locked->renews_on,
                    'external_reference' => $externalReference ?? $locked->external_reference,
                    'renewed_at' => CarbonImmutable::now(),
                    'invalidated_at' => null,
                    'invalidated_by' => null,
                    'invalidated_reason' => null,
                    // Reset before recomputing, so a licence that was Invalid
                    // is assessed by its new dates rather than left there.
                    'state' => LicenceState::Pending,
                ]);

                $locked->state = $locked->stateFromDates();

                if ($locked->state !== $from) {
                    $locked->state_changed_at = CarbonImmutable::now();
                }

                $locked->save();

                return $locked;
            },
            describe: fn (Licence $locked): AuditedAct => new AuditedAct(
                action: AuditAction::LicenceRenewed,
                subject: $locked,
                context: [
                    'product' => $locked->product,
                    'environment' => $locked->environment->value,
                    'expires_on' => $expiresOn->toDateString(),
                    'state' => $locked->state->value,
                    'operator' => $operator->getKey(),
                ],
            ),
        );

        $renewed->providerInstances()
            ->with(['server', 'credential', 'licence', 'capabilities'])
            ->each(fn (ProviderInstance $provider) => $this->assess->execute($provider));

        return $renewed;
    }
}
