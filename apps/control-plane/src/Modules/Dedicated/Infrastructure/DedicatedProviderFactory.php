<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure;

use Lynomia\Modules\Dedicated\Domain\Contracts\DedicatedProvider;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Exceptions\BmcNotConfiguredException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\UnknownBmcProtocolException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\BmcConnection;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\FakeDedicatedProvider;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\IloDedicatedProvider;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\IpmiDedicatedProvider;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\RedfishDedicatedProvider;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Builds the adapter for one BMC endpoint.
 *
 * A factory rather than a registry keyed by name, for the same reason the
 * compute module has one: a BMC adapter is not a singleton. Every controller
 * has its own address, its own certificate policy and its own credential, and
 * resolving "the Redfish provider" without saying which machine would be
 * meaningless. The one shape of that mistake that matters — an operation aimed
 * at the wrong physical server — is what this signature makes impossible, and
 * the adapters check it again at every entry point because the consequence
 * (somebody else's host power cycled or reinstalled) cannot be undone.
 *
 * Credentials are read from configuration here and never from the endpoint
 * row, which holds only a reference to them.
 *
 * Instances are memoised per endpoint: an inventory sync makes several calls
 * against one controller, and rebuilding the HTTP stack for each would be pure
 * waste on a device whose entire CPU is slower than a phone's.
 */
final class DedicatedProviderFactory
{
    /**
     * The configuration value that swaps every adapter for the fake.
     *
     * Read from config rather than from the endpoint row because, unlike a
     * hypervisor driver, "fake" is not a protocol a controller can speak: no
     * bmc_endpoints row may ever name it, or a single bad row would make one
     * machine silently unmanaged in production.
     */
    private const string FAKE_DRIVER = 'fake';

    /** @var array<string, DedicatedProvider> */
    private array $resolved = [];

    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {}

    /**
     * @throws UnknownBmcProtocolException
     * @throws BmcNotConfiguredException
     */
    public function for(BmcEndpoint $endpoint): DedicatedProvider
    {
        $key = (string) $endpoint->getKey();

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        if ($this->fakeIsConfigured()) {
            // The fake reports the protocol the row names, so a test of the
            // iLO path still sees iLO. The guard inside the fake is what stops
            // this branch existing in production, and it fires on
            // construction rather than trusting this check.
            return $this->resolved[$key] = new FakeDedicatedProvider($endpoint->protocol);
        }

        $connection = $this->connectionFor($endpoint);

        return $this->resolved[$key] = match ($endpoint->protocol) {
            BmcProtocol::Redfish => new RedfishDedicatedProvider($connection, $this->redactor),
            BmcProtocol::Ilo => new IloDedicatedProvider($connection, $this->redactor),
            BmcProtocol::Ipmi => new IpmiDedicatedProvider($connection, $this->redactor),
            // Reached when the protocol enum gains a case ahead of its adapter,
            // which is how an endpoint row comes to name a protocol the running
            // code cannot speak.
            default => throw UnknownBmcProtocolException::named(
                $endpoint->protocol->value,
                array_map(static fn (BmcProtocol $p): string => $p->value, BmcProtocol::cases()),
            ),
        };
    }

    /**
     * Replaces the adapter for one endpoint for the lifetime of the container.
     *
     * Exists for tests that need a controller to misbehave — a reset that
     * times out, a health read that reports a dying disk — which cannot be
     * arranged through configuration alone.
     */
    public function swap(BmcEndpoint $endpoint, DedicatedProvider $provider): void
    {
        $this->resolved[(string) $endpoint->getKey()] = $provider;
    }

    /**
     * @throws BmcNotConfiguredException
     */
    private function connectionFor(BmcEndpoint $endpoint): BmcConnection
    {
        $endpointId = (string) $endpoint->getKey();

        if (trim($endpoint->address) === '') {
            throw BmcNotConfiguredException::missingAddress($endpointId);
        }

        $reference = $endpoint->credentialsReference();

        /** @var array<string, mixed> $credentials */
        $credentials = config('dedicated.credentials.'.$reference, []);

        $username = (string) ($credentials['username'] ?? $endpoint->username ?? '');
        $password = (string) ($credentials['password'] ?? '');

        /*
         * No default credentials, ever, and no anonymous fallback. Vendor
         * defaults are published, and a controller reached with them is a
         * complete out-of-band computer — power control and virtual media —
         * handed to whoever tried them first.
         */
        if ($username === '' || $password === '') {
            throw BmcNotConfiguredException::missingCredentials($endpointId, $reference);
        }

        return new BmcConnection(
            endpointId: $endpointId,
            protocol: $endpoint->protocol,
            address: $endpoint->address,
            port: $endpoint->effectivePort(),
            username: $username,
            password: $password,
            /*
             * The row decides, and only for itself. config() supplies the
             * fleet default for an endpoint that has expressed no preference
             * and can never override one that has — the mistake being ruled
             * out is a single environment variable switching certificate
             * verification off for every controller at once, which hands the
             * Basic credential for every physical host to whoever answers the
             * connection.
             */
            verifyTls: $endpoint->verify_tls,
            timeoutSeconds: $this->timeoutFor($endpoint),
            systemId: (string) ($credentials['system_id'] ?? '1'),
        );
    }

    /**
     * IPMI gets its own timeout because it behaves nothing like an HTTP call:
     * ipmitool retries a UDP request internally before giving up, so a value
     * tuned for a JSON round trip would kill the process mid-retry and turn
     * every slow controller into an indeterminate power operation.
     */
    private function timeoutFor(BmcEndpoint $endpoint): int
    {
        $key = $endpoint->protocol === BmcProtocol::Ipmi
            ? 'dedicated.bmc.ipmi_timeout_seconds'
            : 'dedicated.bmc.timeout_seconds';

        return max(1, (int) config($key, 60));
    }

    /**
     * Whether configuration has replaced every controller with the fake.
     *
     * Defaults to false: an absent or unreadable setting must never mean
     * "fake". A platform that silently faked its BMC layer would report
     * servers as installed without installing them.
     */
    private function fakeIsConfigured(): bool
    {
        return config('dedicated.provider') === self::FAKE_DRIVER;
    }
}
