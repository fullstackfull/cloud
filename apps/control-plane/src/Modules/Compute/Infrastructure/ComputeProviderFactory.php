<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Infrastructure;

use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\Enums\ComputeDriver;
use Lynomia\Modules\Compute\Domain\Exceptions\ClusterNotConfiguredException;
use Lynomia\Modules\Compute\Domain\Exceptions\UnknownComputeDriverException;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxComputeProvider;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxConnection;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Builds the adapter for one cluster.
 *
 * A factory rather than a registry keyed by name, because a compute adapter is
 * not a singleton the way a payment adapter is: every cluster has its own
 * endpoint, its own certificate policy and its own token. Resolving "the
 * Proxmox provider" without saying which cluster would be meaningless, and the
 * one shape of that mistake that matters — an operation aimed at the wrong
 * cluster — is exactly what this signature makes impossible.
 *
 * Credentials are read from configuration at this point and never from the
 * cluster row, which holds only a reference to them.
 *
 * Instances are memoised per cluster: an inventory sync makes several calls,
 * and rebuilding the HTTP stack for each would be pure waste.
 */
final class ComputeProviderFactory
{
    /** @var array<string, ComputeProvider> */
    private array $resolved = [];

    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {}

    /**
     * @throws UnknownComputeDriverException
     * @throws ClusterNotConfiguredException
     */
    public function for(ComputeCluster $cluster): ComputeProvider
    {
        $key = (string) $cluster->getKey();

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        return $this->resolved[$key] = match ($cluster->driver) {
            ComputeDriver::Fake => new FakeComputeProvider,
            ComputeDriver::Proxmox => $this->proxmox($cluster),
            // Reached when the driver enum gains a case ahead of its adapter,
            // which is how a cluster row comes to name a driver the running
            // code cannot build.
            default => throw UnknownComputeDriverException::named(
                $cluster->driver->value,
                [ComputeDriver::Proxmox->value, ComputeDriver::Fake->value],
            ),
        };
    }

    /**
     * Replaces the adapter for one cluster for the lifetime of the container.
     *
     * Exists for tests that need a hypervisor to misbehave — a create that
     * times out, a node listing that throws — which cannot be arranged through
     * configuration alone.
     */
    public function swap(ComputeCluster $cluster, ComputeProvider $provider): void
    {
        $this->resolved[(string) $cluster->getKey()] = $provider;
    }

    private function proxmox(ComputeCluster $cluster): ComputeProvider
    {
        $endpoint = (string) $cluster->api_endpoint;

        if (trim($endpoint) === '') {
            throw ClusterNotConfiguredException::missingEndpoint((string) $cluster->getKey());
        }

        $reference = $cluster->credentials_reference ?? $cluster->slug;

        /** @var array<string, mixed> $credentials */
        $credentials = config('compute.credentials.'.$reference, []);

        if ($credentials === [] || ($credentials['token_id'] ?? '') === '' || ($credentials['token_secret'] ?? '') === '') {
            throw ClusterNotConfiguredException::missingCredentials((string) $cluster->getKey(), $reference);
        }

        return new ProxmoxComputeProvider(
            ProxmoxConnection::fromCredentials(
                endpoint: $endpoint,
                credentials: $credentials,
                // The row is the only thing that can waive verification, and it
                // waives it for itself alone: a cluster that asks for
                // verification gets it however config has been loosened.
                verifyTls: $cluster->verify_tls,
            ),
            $this->redactor,
        );
    }
}
