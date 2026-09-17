<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Testers;

use Lynomia\Modules\Providers\Domain\Contracts\ConnectionTester;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionStep;
use Lynomia\Modules\Providers\Domain\DTOs\TestTarget;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\ControlledDriver;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use RuntimeException;

/**
 * A tester that fails in all the ways a real one does.
 *
 * ---------------------------------------------------------------------------
 * Why this is not a mock that returns Connected
 * ---------------------------------------------------------------------------
 *
 * Because every interesting thing in this control centre is a failure path.
 * The screens exist to tell an operator apart a wrong credential from an
 * unreachable host from a lapsed licence; the readiness engine exists to say
 * which of those is blocking a product from being sold. A fake that always
 * succeeds exercises none of it, and leaves the platform's most valuable
 * behaviour tested only by unit tests of the enum.
 *
 * So the outcome is chosen from the endpoint. An operator — or a test —
 * writes the failure they want into the hostname, and the whole chain from
 * tester through readiness to the blocker shown on a screen runs for real.
 *
 *   fake://auth-failed          the credential is rejected
 *   fake://network-failed       nothing answers
 *   fake://tls-failed           it answers and the handshake does not complete
 *   fake://timeout              it accepts and never replies      (indeterminate)
 *   fake://licence-missing      authenticated, product unlicensed
 *   fake://read-only            authenticated, may look and not touch
 *   fake://unsupported          connected, and no capability is offered at all
 *   fake://unavailable          the provider is having an outage
 *   fake://slow                 succeeds, late enough to be worth noticing
 *   anything else               connected, with the capabilities this driver offers
 *
 * ---------------------------------------------------------------------------
 * The production guard
 * ---------------------------------------------------------------------------
 *
 * In the constructor, not at the call site. A fake provider reachable in
 * production is not a bug to be caught in review — the application refuses to
 * build one, so a misconfigured deployment fails to boot rather than quietly
 * telling an operator that a machine nobody has bought is connected.
 *
 * And a second guard, on the target rather than on the deployment, because the
 * first cannot see the case that matters most. A staging deployment is allowed
 * to build this class; a *production provider row* tested from that staging
 * deployment would still get an imaginary Connected, and that row is the one
 * the readiness engine consults before a product goes on sale. So the
 * environment of the thing being tested is checked too, and the two controls
 * together mean a production row cannot be told it is connected by a fake from
 * anywhere.
 */
final class FakeConnectionTester implements ConnectionTester
{
    /**
     * @param  string  $driver  Which catalogued driver this instance answers for. One
     *                          tester stands in for every controlled driver — a remote
     *                          account, a machine's BMC, a hypervisor, a panel, a
     *                          registrar, a gateway — so the whole onboarding path can
     *                          be rehearsed without any of them existing. Which one it
     *                          was told it is deciding what capabilities it reports:
     *                          see {@see ControlledDriver}.
     */
    public function __construct(private readonly string $environment, private readonly string $driver = 'fake')
    {
        if ($this->environment === 'production') {
            throw new RuntimeException(
                'The fake connection tester must never be built in production. '
                .'A control centre that reports imaginary machines as reachable '
                .'is worse than one that reports nothing.'
            );
        }
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * A fixed hardware inventory, or nothing.
     *
     * Nothing when the target is unreachable or refuses the credential — the
     * same markers that fail test() — because a discovery that reports a
     * serial number for a machine it never authenticated to has invented one.
     *
     * @return array<string, string>
     */
    public function discover(TestTarget $target): array
    {
        $this->refuseProductionTargets($target);

        $marker = $this->markerIn($target->endpoint);

        if (in_array($marker, ['network-failed', 'tls-failed', 'timeout', 'unavailable', 'auth-failed'], true) || ! $target->hasSecret()) {
            return [];
        }

        return [
            'vendor' => 'Fabrikam',
            'model' => 'FX-2200',
            'serial' => 'FX2200-'.strtoupper(substr(md5($target->identity ?? 'anonymous'), 0, 8)),
            'bmc.firmware' => '2.14.0',
            'cpu.model' => 'Contoso 32-core',
            'cpu.sockets' => '2',
            'memory.total_mib' => '262144',
            'disk.0' => 'nvme 3840 GiB',
            'disk.1' => 'nvme 3840 GiB',
            'power.state' => 'on',
        ];
    }

    public function test(TestTarget $target): ConnectionResult
    {
        $this->refuseProductionTargets($target);

        $marker = $this->markerIn($target->endpoint);

        // Reachability comes first, because nothing below it can be known
        // without it. A test that reports "auth failed" for a host that never
        // answered has invented an answer.
        if ($marker === 'network-failed') {
            return ConnectionResult::of(
                ConnectionState::NetworkFailed,
                [ConnectionStep::failed('tcp', 'no route to host')],
                $this->allUnknown($target, CapabilityState::BlockedNetwork),
            );
        }

        $steps = [ConnectionStep::passed('tcp')];

        if ($marker === 'tls-failed') {
            $steps[] = ConnectionStep::failed('tls', 'certificate does not cover this name');

            return ConnectionResult::of(
                ConnectionState::TlsFailed,
                $steps,
                $this->allUnknown($target, CapabilityState::BlockedNetwork),
            );
        }

        $steps[] = ConnectionStep::passed('tls');

        if ($marker === 'timeout') {
            $steps[] = ConnectionStep::failed('authenticate', 'no response before the deadline');

            // NeedsReview rather than a failure. The target accepted the
            // connection and never answered, so whether it acted is unknown —
            // and under the Timeout Rule an unknown outcome is a person's
            // decision, never an automatic retry.
            return ConnectionResult::of(
                ConnectionState::NeedsReview,
                $steps,
                $this->allUnknown($target, CapabilityState::Unknown),
                'The endpoint accepted the connection and did not answer. Whether it acted is unknown.',
            );
        }

        if ($marker === 'unavailable') {
            $steps[] = ConnectionStep::failed('authenticate', 'provider returned a service outage');

            return ConnectionResult::of(
                ConnectionState::ProviderUnavailable,
                $steps,
                $this->allUnknown($target, CapabilityState::Unknown),
            );
        }

        if (! $target->hasSecret() || $marker === 'auth-failed') {
            $steps[] = ConnectionStep::failed('authenticate', 'the endpoint rejected the credential');

            return ConnectionResult::of(
                ConnectionState::AuthFailed,
                $steps,
                $this->allUnknown($target, CapabilityState::BlockedCredentials),
            );
        }

        $steps[] = ConnectionStep::passed('authenticate');

        if ($marker === 'licence-missing') {
            $steps[] = ConnectionStep::failed('licence', 'the product reports no valid licence');

            return ConnectionResult::of(
                ConnectionState::LicenceMissing,
                $steps,
                $this->allUnknown($target, CapabilityState::BlockedLicence),
            );
        }

        $steps[] = ConnectionStep::passed('licence');

        if ($marker === 'read-only') {
            $steps[] = ConnectionStep::passed('permissions', 'the account may read and not write');

            // Correct and useful, not a failure: this is exactly what a
            // PVEAuditor token looks like, and exactly what a machine being
            // onboarded read-only should have.
            return ConnectionResult::of(
                ConnectionState::ConnectedReadOnly,
                $steps,
                $this->readOnlyCapabilities($target),
            );
        }

        $steps[] = ConnectionStep::passed('permissions');

        if ($marker === 'unsupported') {
            $steps[] = ConnectionStep::passed('capabilities', 'the account does not offer every capability asked about');

            return ConnectionResult::of(
                ConnectionState::Connected,
                $steps,
                $this->allOf($target, CapabilityState::Unsupported),
            );
        }

        $steps[] = ConnectionStep::passed('capabilities');

        return ConnectionResult::of(
            ConnectionState::Connected,
            $steps,
            $this->whatThisDriverOffers($target),
        );
    }

    /**
     * A production row is never answered by a fake, wherever this is running.
     *
     * An exception rather than a failed result, and the contract allows
     * exactly this: {@see ConnectionTester}
     * reserves them for a caller having asked for something impossible, as
     * opposed to for an ordinary failure like an unreachable host. Answering
     * NetworkFailed here would be worse than throwing — it would look like a
     * real test of a real provider that happened to fail, and somebody would
     * spend a morning on the network.
     */
    private function refuseProductionTargets(TestTarget $target): void
    {
        if ($target->environment === DeploymentEnvironment::Production) {
            throw new RuntimeException(
                'The fake connection tester must never answer for a production provider row. '
                .'A production row is what the readiness engine consults before a product is offered for sale, '
                .'and an imaginary Connected on one is how a customer buys something that does not exist.'
            );
        }
    }

    private function markerIn(?string $endpoint): string
    {
        if ($endpoint === null) {
            return '';
        }

        $host = parse_url($endpoint, PHP_URL_HOST);

        return is_string($host) ? $host : trim($endpoint, '/');
    }

    /**
     * Capabilities that need a write to establish stay Unknown even here.
     *
     * A read-only connection has genuinely learned that inspection works and
     * genuinely learned nothing about creation, and saying otherwise would be
     * the fake teaching the platform a lie. A capability this driver does not
     * offer at all is reported as unsupported whether it is readable or not:
     * a read-only credential does not make an absent operation appear.
     *
     * @return array<string, CapabilityState>
     */
    private function readOnlyCapabilities(TestTarget $target): array
    {
        $readable = ['inventory', 'power_state', 'templates', 'search', 'availability', 'usage', 'version', 'currencies', 'held_names'];

        $offered = $this->whatThisDriverOffers($target);

        $states = [];

        foreach ($target->probeCapabilities as $capability) {
            if (($offered[$capability] ?? CapabilityState::Supported) === CapabilityState::Unsupported) {
                $states[$capability] = CapabilityState::Unsupported;

                continue;
            }

            $states[$capability] = in_array($capability, $readable, strict: true)
                ? CapabilityState::Supported
                : CapabilityState::Unknown;
        }

        return $states;
    }

    /**
     * What the simulator behind this driver can actually do.
     *
     * The earlier version of this method answered Supported for every
     * capability the category asks about, and for the two drivers that existed
     * then that was true. It stopped being true the moment a controlled driver
     * was catalogued for a category whose questions outrun its contract — a
     * controlled hypervisor asked about GPU passthrough, a controlled
     * WordPress toolkit asked whether it can uninstall. Answering Supported
     * there would be a fake teaching the readiness engine that the platform
     * can do something no code path exists for, and the readiness engine is
     * what a product is offered for sale on.
     *
     * {@see ControlledDriver::unsupported()} holds each answer with the
     * missing contract as its reason.
     *
     * @return array<string, CapabilityState>
     */
    private function whatThisDriverOffers(TestTarget $target): array
    {
        $controlled = ControlledDriver::tryFrom($this->driver);

        $states = [];

        foreach ($target->probeCapabilities as $capability) {
            $states[$capability] = $controlled === null
                ? CapabilityState::Supported
                : $controlled->stateOf($capability);
        }

        return $states;
    }

    /**
     * @return array<string, CapabilityState>
     */
    private function allOf(TestTarget $target, CapabilityState $state): array
    {
        return array_fill_keys($target->probeCapabilities, $state);
    }

    /**
     * @return array<string, CapabilityState>
     */
    private function allUnknown(TestTarget $target, CapabilityState $state): array
    {
        return array_fill_keys($target->probeCapabilities, $state);
    }
}
