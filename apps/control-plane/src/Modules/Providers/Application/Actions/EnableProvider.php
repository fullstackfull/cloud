<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Domain\Events\ProviderReadinessChanged;
use Lynomia\Modules\Providers\Domain\Exceptions\ProviderRefused;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;

/**
 * Start routing real work to a provider.
 *
 * ---------------------------------------------------------------------------
 * Why this is the narrowest action in the module
 * ---------------------------------------------------------------------------
 *
 * Enabling is the moment a provider stops being a description and starts being
 * where customers' money, machines and domains go. Everything else here can be
 * corrected by editing a row; this one is observable from outside the company.
 *
 * Three things guard it and they are deliberately not one check:
 *
 *   - readiness is reassessed inside the transaction, not read from the column.
 *     The stored value was true when it was written, and "was true recently" is
 *     not the standard for the act that starts taking orders;
 *   - the row is locked, so two operators cannot both read Ready and both
 *     enable;
 *   - the database refuses a second enabled provider in the same category and
 *     environment. That refusal is the one that survives a race the lock does
 *     not cover — two requests against two different rows — and it is caught
 *     and translated rather than allowed to surface as a 500.
 */
final readonly class EnableProvider
{
    public function __construct(
        private AssessProvider $assess,
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws ProviderRefused
     */
    public function execute(ProviderInstance $provider, User $operator): ProviderInstance
    {
        try {
            return $this->record->execute(
                act: function () use ($provider, $operator): ProviderInstance {
                    /** @var ProviderInstance $locked */
                    $locked = ProviderInstance::query()
                        ->whereKey($provider->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    // Relations are re-read against the locked row rather than
                    // reused from the caller's copy: the credential could have
                    // been revoked between the screen loading and the button
                    // being pressed, and that is exactly the window this is here
                    // to close.
                    $locked->load(['server', 'credential', 'licence', 'capabilities']);

                    $verdict = $this->assess->execute($locked);

                    if (! $verdict->isReady()) {
                        throw ProviderRefused::notReady($locked->name, $verdict);
                    }

                    $locked->forceFill([
                        'state' => ProviderState::Enabled,
                        'enabled_at' => CarbonImmutable::now(),
                        'enabled_by' => $operator->getKey(),
                        'disabled_reason' => null,
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
                describe: fn (ProviderInstance $enabled): AuditedAct => new AuditedAct(
                    action: AuditAction::ProviderEnabled,
                    subject: $enabled,
                    context: [
                        'provider' => $enabled->name,
                        'category' => $enabled->category->value,
                        'environment' => $enabled->environment->value,
                        'driver' => $enabled->driver,
                        'operator' => $operator->getKey(),
                    ],
                ),
            );
        } catch (ProviderRefused $refusal) {
            /*
             * The refusal rolled its transaction back, and that took the
             * reassessment with it — so without this the operator would be
             * told the provider is not ready and then shown a screen still
             * claiming it is, which reads as the refusal being the mistake.
             *
             * Reassessed on a fresh read outside the failed transaction, so
             * the row records what was actually found. The refusal is then
             * re-thrown: recording the truth is not the same as accepting the
             * request.
             */
            $current = ProviderInstance::query()->find($provider->getKey());

            if ($current !== null) {
                $this->assess->execute($current->load(['server', 'credential', 'licence', 'capabilities']));
            }

            throw $refusal;
        } catch (QueryException $e) {
            /*
             * The partial unique index on (category, environment) where state
             * is enabled. Translated rather than propagated because the
             * database's own message names an index, and an operator reading
             * "duplicate key value violates unique constraint" learns nothing
             * about the decision they need to make.
             */
            if (str_contains($e->getMessage(), 'provider_instances_one_enabled_per_category')) {
                throw ProviderRefused::anotherIsEnabled($provider->category, $provider->environment);
            }

            throw $e;
        }
    }
}
