<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\InfrastructureAction;
use Lynomia\Modules\Infrastructure\Domain\Services\SafetyGate;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Application\Services\ProbeProvider;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Providers\Domain\DTOs\ServerDiscovery;
use Lynomia\Modules\Providers\Domain\DTOs\TestTarget;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Infrastructure\Models\ConnectionTest as ConnectionTestRecord;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderCapability;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;

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
 * Resolved by {@see ProbeProvider}, at the last possible moment, into a
 * TestTarget that redacts itself in a var_dump and is never persisted. The
 * provider row, the audit entry and the connection_tests row all record what
 * happened; none of them can record what was sent, because none of them is
 * ever given it.
 *
 * ---------------------------------------------------------------------------
 * What is left here, now that the probe is its own service
 * ---------------------------------------------------------------------------
 *
 * The recording half. Finding out whether something answers is
 * {@see ProbeProvider}'s job and changes nothing; this action writes down what
 * was found, and the writing is what makes it an action: the provider row's
 * state and timestamps, every capability row, the credential's state, a
 * connection_tests record, and an audit entry.
 *
 * The split exists because Phase 30B-SIM needed a preflight that observes
 * without moving the platform's own state — a diagnosis that marked a
 * credential Invalid while explaining why a product cannot be sold would have
 * changed the answer it was asked about. Everything security-critical about
 * reaching a provider moved into the probe, so there is one copy of the
 * endpoint policy call, the credential rule and the controlled-driver guard
 * rather than two that drift.
 */
final readonly class TestConnection
{
    public function __construct(
        private ProbeProvider $probe,
        private SafetyGate $gate,
        private RecordActAtomically $record,
    ) {}

    /**
     * Test the path to a machine.
     *
     * Uses the BMC address when there is one and the management address
     * otherwise: during onboarding the BMC is usually reachable before the
     * operating system exists, which is the point of having one.
     *
     * The driver is the one bound to the machine as its BMC provider. It is
     * not a parameter, and that is a security property rather than a
     * convenience: a caller that could name the driver could point a test —
     * and the credential resolved for it — at any adapter the platform has.
     */
    public function forServer(ManagedServer $server, ?User $operator = null): ConnectionTestRecord
    {
        $this->gate->assert($server->name, $server->safety_class, $server->allow_reimage, InfrastructureAction::Read);

        $target = $this->probe->targetForServer($server);
        $result = $this->probe->testerFor($target->driver, $target->environment)->test($target);

        return $this->recordServerTest($server, $target->driver, $result);
    }

    /**
     * Look at a machine, and bring back what it says about itself.
     *
     * The same gate as a test — a read is a read — and the same tester, in
     * one session: test first, and only if the machine actually answered
     * usefully, ask it for its inventory. A machine that refused the
     * credential has no facts to give, and the discovery says so by returning
     * none rather than by reporting whatever the last person typed.
     */
    public function discoverServer(ManagedServer $server, ?User $operator = null): ServerDiscovery
    {
        $this->gate->assert($server->name, $server->safety_class, $server->allow_reimage, InfrastructureAction::Read);

        $target = $this->probe->targetForServer($server);
        $tester = $this->probe->testerFor($target->driver, $target->environment);
        $result = $tester->test($target);

        $test = $this->recordServerTest($server, $target->driver, $result);

        $facts = $result->state->usable() ? $tester->discover($target) : [];

        return new ServerDiscovery($test, $facts);
    }

    private function recordServerTest(ManagedServer $server, string $driver, ConnectionResult $result): ConnectionTestRecord
    {
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
        $result = $this->probe->probe($provider);

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
}
