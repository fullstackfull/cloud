<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Providers\Infrastructure\Models\Licence;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;

/**
 * Let the calendar move licence states, and everything that depends on them.
 *
 * ---------------------------------------------------------------------------
 * Why a sweep rather than computing on read
 * ---------------------------------------------------------------------------
 *
 * A licence state is read by the readiness engine, which stores its verdict,
 * and by list screens, which should not load a provider's whole dependency
 * tree per row. So the state is stored — which means a licence that expires
 * at midnight is still "active" in the database at 00:01 until something
 * looks. This is the something: scheduled daily, and runnable by an operator
 * who does not want to wait.
 *
 * Only transitions the calendar can make are made here. Invalid and
 * NotRequired are decisions, not dates, and the sweep leaves them alone.
 * Each change is audited individually, because "the cPanel licence went from
 * expiring to expired on the 14th" is a row somebody will want to find.
 *
 * @phpstan-type Outcome array{examined: int, changed: int, providers_reassessed: int}
 */
final readonly class RefreshLicenceStates
{
    public function __construct(
        private AssessProvider $assess,
        private RecordActAtomically $record,
    ) {}

    /**
     * @return Outcome
     */
    public function execute(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $examined = 0;
        $changed = 0;
        $reassessed = 0;

        Licence::query()
            ->whereNotIn('state', [LicenceState::Invalid->value, LicenceState::NotRequired->value])
            ->orderBy('id')
            ->each(function (Licence $licence) use ($now, &$examined, &$changed, &$reassessed): void {
                $examined++;
                $from = $licence->state;
                $to = $licence->stateFromDates(now: $now);

                if ($to === $from) {
                    return;
                }

                $this->record->execute(
                    act: function () use ($licence, $to, $now): Licence {
                        $licence->forceFill(['state' => $to, 'state_changed_at' => $now])->save();

                        return $licence;
                    },
                    describe: fn (Licence $updated): AuditedAct => new AuditedAct(
                        action: AuditAction::LicenceStateChanged,
                        subject: $updated,
                        context: [
                            'product' => $updated->product,
                            'environment' => $updated->environment->value,
                            'from' => $from->value,
                            'to' => $to->value,
                            'expires_on' => $updated->expires_on?->toDateString(),
                        ],
                    ),
                );

                $changed++;

                $licence->providerInstances()
                    ->with(['server', 'credential', 'licence', 'capabilities'])
                    ->each(function (ProviderInstance $provider) use (&$reassessed): void {
                        $this->assess->execute($provider);
                        $reassessed++;
                    });
            });

        return ['examined' => $examined, 'changed' => $changed, 'providers_reassessed' => $reassessed];
    }
}
