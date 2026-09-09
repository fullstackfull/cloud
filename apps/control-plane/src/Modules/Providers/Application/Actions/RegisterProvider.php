<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Actions;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Domain\Exceptions\ProviderRefused;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;

/**
 * Declare that we intend to talk to something.
 *
 * ---------------------------------------------------------------------------
 * Registration is not adoption
 * ---------------------------------------------------------------------------
 *
 * Nothing is contacted here. A registered provider is a row saying "this is
 * meant to exist and here is where to look for it", in Draft, connected to
 * nothing. Everything that makes it usable — a credential, a licence, a
 * successful test, discovered capabilities — is a separate act with its own
 * refusal, and enabling it is a separate decision again.
 *
 * The checks that do run are the ones whose failure could not be discovered
 * later without somebody being misled: a driver nothing implements, a category
 * that disagrees with the adapter, a machine in another environment, and the
 * simulated driver in production.
 */
final readonly class RegisterProvider
{
    public function __construct(
        private ProviderCatalogue $catalogue,
        private AssessProvider $assess,
        private RecordActAtomically $record,
        private Application $app,
    ) {}

    /**
     * @throws ProviderRefused
     */
    public function execute(
        string $name,
        string $driver,
        ProviderCategory $category,
        DeploymentEnvironment $environment,
        User $operator,
        ?string $endpoint = null,
        ?ManagedServer $server = null,
        ?string $notes = null,
    ): ProviderInstance {
        $entry = $this->catalogue->find($driver);

        if ($entry === null) {
            throw ProviderRefused::unknownDriver($driver, array_map(
                static fn ($known): string => $known->driver,
                $this->catalogue->entries(),
            ));
        }

        if ($entry->category !== $category) {
            throw ProviderRefused::categoryMismatch($driver, $category, $entry->category);
        }

        /*
         * Checked against the running environment rather than against the
         * provider row's environment, and the difference matters: this refuses
         * a simulated provider on the production installation even when the
         * row claims to be for staging. The row is data an operator supplied;
         * the installation is a fact.
         */
        if ($this->app->environment('production') && in_array($driver, $this->catalogue->controlledDrivers(), true)) {
            throw ProviderRefused::controlledDriverInProduction($driver);
        }

        if ($entry->needsServer() && $server === null) {
            throw ProviderRefused::needsAServer($driver);
        }

        if ($server !== null && ! $server->environment->satisfies($environment)) {
            throw ProviderRefused::serverIsElsewhere($server->name, $server->environment, $environment);
        }

        $provider = $this->record->execute(
            act: fn (): ProviderInstance => ProviderInstance::create([
                'name' => $name,
                'category' => $category,
                'driver' => $driver,
                'environment' => $environment,
                'endpoint' => $endpoint,
                'managed_server_id' => $server?->getKey(),
                'notes' => $notes,

                /*
                 * Set here rather than left to the column defaults. A default
                 * applies in the database and not on the model create() hands
                 * back, and this row is rendered straight into the response —
                 * which is precisely how RegisterServer produced a blank state
                 * and a 500 earlier in this phase. The models declare these
                 * too; stating them at the one place that decides them keeps
                 * the intent readable.
                 */
                'state' => ProviderState::Draft,
                'connection_state' => ConnectionState::NotTested,
                'readiness' => ReadinessState::NotReady,
            ]),
            describe: fn (ProviderInstance $created): AuditedAct => new AuditedAct(
                action: AuditAction::ProviderRegistered,
                subject: $created,
                context: [
                    'provider' => $created->name,
                    'category' => $category->value,
                    'driver' => $driver,
                    'environment' => $environment->value,
                    'server' => $server?->name,
                    'operator' => $operator->getKey(),
                ],
            ),
        );

        // Immediately, so the row an operator is about to look at already says
        // what it is waiting for rather than an unhelpful "not ready".
        $this->assess->execute($provider);

        return $provider;
    }
}
