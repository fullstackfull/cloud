<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Estate\Domain\Contracts\SecretResolver;
use Lynomia\Modules\Estate\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Estate\Domain\DTOs\TestTarget;
use Lynomia\Modules\Estate\Domain\Enums\CapabilityState;
use Lynomia\Modules\Estate\Domain\Enums\CredentialState;
use Lynomia\Modules\Estate\Domain\Enums\EstateAction;
use Lynomia\Modules\Estate\Domain\Enums\EstateEnvironment;
use Lynomia\Modules\Estate\Domain\Services\SafetyGate;
use Lynomia\Modules\Estate\Infrastructure\ConnectionTesterFactory;
use Lynomia\Modules\Estate\Infrastructure\Models\ConnectionTest as ConnectionTestRecord;
use Lynomia\Modules\Estate\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Estate\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Estate\Infrastructure\Models\ProviderCapability;
use Lynomia\Modules\Estate\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Find out whether we can reach something, and write down what was found.
 *
 * ---------------------------------------------------------------------------
 * Why a read is still gated
 * ---------------------------------------------------------------------------
 *
 * Because do_not_touch means do not connect. A machine nobody has claimed is
 * one whose owner has not agreed we may open a socket to it, and "we were only
 * looking" is how an unrelated production system ends up in somebody's logs.
 * A test is the least dangerous thing this module does and it still asks.
 *
 * ---------------------------------------------------------------------------
 * Where the secret is, and for how long
 * ---------------------------------------------------------------------------
 *
 * Resolved here, at the last possible moment, into a TestTarget that redacts
 * itself in a var_dump and is never persisted. The provider row, the audit
 * entry and the connection_tests row all record what happened; none of them can
 * record what was sent, because none of them is ever given it.
 */
final readonly class TestConnection
{
    public function __construct(
        private ConnectionTesterFactory $testers,
        private SecretResolver $secrets,
        private SafetyGate $gate,
        private RecordActAtomically $record,
    ) {}

    /**
     * Test the path to a machine.
     *
     * Uses the BMC address when there is one and the management address
     * otherwise: during onboarding the BMC is usually reachable before the
     * operating system exists, which is the point of having one.
     */
    public function forServer(ManagedServer $server, string $driver, ?User $operator = null): ConnectionTestRecord
    {
        $this->gate->assert($server->name, $server->safety_class, $server->allow_reimage, EstateAction::Read);

        $endpoint = $server->bmc_address ?? $server->management_address;

        $result = $this->run($driver, new TestTarget(
            driver: $driver,
            environment: $server->environment,
            endpoint: $endpoint,
            secret: $this->secretFor($server->credential, $server->environment),
            probeCapabilities: [],
            identity: $server->name,
        ));

        return $this->record->execute(
            act: function () use ($server, $result): ConnectionTestRecord {
                $server->forceFill([
                    'connection_state' => $result->state,
                    'last_connection_test_at' => CarbonImmutable::now(),
                ])->save();

                return ConnectionTestRecord::create([
                    'managed_server_id' => $server->getKey(),
                    'result' => $result->state,
                    'steps' => $result->stepsAsArray(),
                    'detail' => $result->detail,
                ]);
            },
            describe: fn (ConnectionTestRecord $test): AuditedAct => new AuditedAct(
                action: AuditAction::ConnectionTested,
                subject: $test,
                context: [
                    'server' => $server->name,
                    'driver' => $driver,
                    'result' => $result->state->value,
                ],
            ),
        );
    }

    /**
     * Test a provider account, and record what it said it can do.
     *
     * The capabilities are the reason this writes more than a state. Asking
     * "can this account create a VM" once and remembering the answer is what
     * lets the readiness engine say why a product cannot be sold, instead of
     * asking every provider every question every time a screen loads.
     */
    public function forProvider(ProviderInstance $provider, ?User $operator = null): ConnectionTestRecord
    {
        $secret = $this->secretFor($provider->credential, $provider->environment);

        $result = $this->run($provider->driver, new TestTarget(
            driver: $provider->driver,
            environment: $provider->environment,
            endpoint: $provider->endpoint,
            secret: $secret,
            probeCapabilities: $provider->category->capabilities(),
            identity: $provider->name,
        ));

        return $this->record->execute(
            act: function () use ($provider, $result): ConnectionTestRecord {
                $provider->forceFill([
                    'connection_state' => $result->state,
                    'connection_detail' => $result->detail,
                    'last_connection_test_at' => CarbonImmutable::now(),
                    'last_discovery_at' => $result->capabilities === [] ? $provider->last_discovery_at : CarbonImmutable::now(),
                ])->save();

                $this->recordCapabilities($provider, $result);

                if ($provider->credential !== null) {
                    $provider->credential->forceFill([
                        'state' => match (true) {
                            $result->state->usable() => CredentialState::Valid,
                            $result->state->blocker()?->value === 'blocked_credentials' => CredentialState::Invalid,
                            // Everything else — an unreachable host, a provider
                            // outage — says nothing about the credential, and
                            // marking it invalid would send an operator to
                            // rotate a key that is fine.
                            default => $provider->credential->state,
                        },
                        'last_tested_at' => CarbonImmutable::now(),
                    ])->save();
                }

                return ConnectionTestRecord::create([
                    'provider_instance_id' => $provider->getKey(),
                    'result' => $result->state,
                    'steps' => $result->stepsAsArray(),
                    'detail' => $result->detail,
                ]);
            },
            describe: fn (ConnectionTestRecord $test): AuditedAct => new AuditedAct(
                action: AuditAction::ConnectionTested,
                subject: $test,
                context: [
                    'provider' => $provider->name,
                    'category' => $provider->category->value,
                    'environment' => $provider->environment->value,
                    'result' => $result->state->value,
                ],
            ),
        );
    }

    private function run(string $driver, TestTarget $target): ConnectionResult
    {
        return $this->testers->for($driver)->test($target);
    }

    /**
     * The secret behind a reference, if it may be used here at all.
     *
     * The environment check comes first and is deliberately a refusal to
     * resolve rather than a refusal to use: a staging token that happens to
     * work against production never enters memory, so nothing downstream has
     * the opportunity to send it by mistake.
     */
    private function secretFor(?CredentialReference $credential, EstateEnvironment $environment): ?string
    {
        if ($credential === null || ! $credential->environment->satisfies($environment)) {
            return null;
        }

        return $this->secrets->resolve($credential->backend, $credential->backend_reference);
    }

    private function recordCapabilities(ProviderInstance $provider, ConnectionResult $result): void
    {
        if ($result->capabilities === []) {
            return;
        }

        $observedAt = CarbonImmutable::now();

        foreach ($result->capabilities as $capability => $state) {
            ProviderCapability::query()->updateOrCreate(
                [
                    'provider_instance_id' => $provider->getKey(),
                    'capability' => $capability,
                ],
                [
                    'state' => $state,
                    'observed_at' => $observedAt,
                ],
            );
        }
    }

    /** @return array<string, CapabilityState> */
    public function capabilitiesOf(ProviderInstance $provider): array
    {
        $states = [];

        foreach ($provider->capabilities as $capability) {
            $states[$capability->capability] = $capability->state;
        }

        return $states;
    }
}
