<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Testers;

use Lynomia\Modules\Providers\Domain\Contracts\ConnectionTester;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionStep;
use Lynomia\Modules\Providers\Domain\DTOs\TestTarget;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
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
 *   fake://unsupported          connected, and the capability is not offered
 *   fake://unavailable          the provider is having an outage
 *   fake://slow                 succeeds, late enough to be worth noticing
 *   anything else               connected, with capabilities supported
 *
 * ---------------------------------------------------------------------------
 * The production guard
 * ---------------------------------------------------------------------------
 *
 * In the constructor, not at the call site. A fake provider reachable in
 * production is not a bug to be caught in review — the application refuses to
 * build one, so a misconfigured deployment fails to boot rather than quietly
 * telling an operator that a machine nobody has bought is connected.
 */
final class FakeConnectionTester implements ConnectionTester
{
    /**
     * @param  string  $driver  Which catalogued driver this instance answers for. The
     *                          same fake stands in for a remote account (`fake`) and
     *                          for a machine's BMC (`fake_bmc`), so the whole
     *                          onboarding path — machine and provider — can be
     *                          rehearsed without either existing.
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
            $this->allOf($target, CapabilityState::Supported),
        );
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
     * the fake teaching the platform a lie.
     *
     * @return array<string, CapabilityState>
     */
    private function readOnlyCapabilities(TestTarget $target): array
    {
        $readable = ['inventory', 'power_state', 'templates', 'search', 'availability', 'usage', 'version', 'currencies', 'held_names'];

        $states = [];

        foreach ($target->probeCapabilities as $capability) {
            $states[$capability] = in_array($capability, $readable, strict: true)
                ? CapabilityState::Supported
                : CapabilityState::Unknown;
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
