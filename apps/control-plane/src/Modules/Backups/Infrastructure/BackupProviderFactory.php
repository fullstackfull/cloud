<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Infrastructure;

use Lynomia\Modules\Backups\Domain\Contracts\BackupProvider;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupNotConfiguredException;
use Lynomia\Modules\Backups\Domain\Exceptions\UnknownBackupDriverException;
use Lynomia\Modules\Backups\Infrastructure\Providers\FakeBackupProvider;
use Lynomia\Modules\Backups\Infrastructure\Providers\ProxmoxBackupProvider;
use Lynomia\Modules\Compute\Domain\Exceptions\ClusterNotConfiguredException;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxConnection;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Builds the backup adapter for one cluster.
 *
 * Per cluster and not per deployment, for the same reason compute is: a fleet
 * of any age has clusters at different versions, in different datacentres, and
 * one of them is the new one being trialled. The `backup` configuration key
 * names the driver family; the cluster row supplies the endpoint and the
 * credential reference.
 *
 * A cluster with no datastore declared cannot be backed up, and that is
 * refused rather than defaulted. A provider will happily write to whichever
 * storage it considers default, and on a hypervisor that is often the same
 * disks the machine runs on — a copy, not a backup, lost by exactly the
 * failure a backup exists for.
 */
final class BackupProviderFactory
{
    /** @var array<string, BackupProvider> */
    private array $resolved = [];

    /**
     * The drivers this build contains.
     *
     * Read here and by the production boot guard. Two lists would eventually
     * disagree, and the way anyone would find out is a deployment that booted
     * clean and could not back anything up.
     *
     * @return list<string>
     */
    public static function drivers(): array
    {
        return [FakeBackupProvider::NAME, ProxmoxBackupProvider::NAME];
    }

    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {}

    /**
     * @throws UnknownBackupDriverException
     * @throws ClusterNotConfiguredException
     */
    public function for(ComputeCluster $cluster): BackupProvider
    {
        $key = (string) $cluster->getKey();

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $driver = strtolower(trim((string) config('billing.providers.backup', FakeBackupProvider::NAME)));

        return $this->resolved[$key] = match ($driver) {
            FakeBackupProvider::NAME => new FakeBackupProvider,
            ProxmoxBackupProvider::NAME => $this->proxmox($cluster),
            default => throw UnknownBackupDriverException::named($driver, self::drivers()),
        };
    }

    /**
     * Where this cluster's backups are written.
     *
     * Declared per cluster in configuration rather than derived, because the
     * datastore is a physical decision — which PBS host, on which disks, in
     * which building — that nothing in the application can infer.
     *
     * @throws BackupNotConfiguredException
     */
    public function datastoreFor(ComputeCluster $cluster): string
    {
        $reference = $cluster->credentials_reference ?? $cluster->slug;
        $key = 'backups.datastores.'.$reference;

        $datastore = trim((string) config($key, ''));

        if ($datastore === '') {
            throw BackupNotConfiguredException::missingDatastore((string) $cluster->getKey(), $key);
        }

        return $datastore;
    }

    /**
     * Replace the adapter for one cluster for the life of the container.
     */
    public function swap(ComputeCluster $cluster, BackupProvider $provider): void
    {
        $this->resolved[(string) $cluster->getKey()] = $provider;
    }

    /**
     * @throws ClusterNotConfiguredException
     */
    private function proxmox(ComputeCluster $cluster): BackupProvider
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

        return new ProxmoxBackupProvider(
            ProxmoxConnection::fromCredentials(
                endpoint: $endpoint,
                credentials: $credentials,
                // The row is the only thing that can waive verification, and
                // it waives it for itself alone.
                verifyTls: $cluster->verify_tls,
                // Longer than the compute default: a vzdump is accepted
                // quickly, but a storage listing on a datastore with thousands
                // of archives is not, and a timeout there is reported as
                // indeterminate and quarantines a row for nothing.
                timeoutSeconds: (int) config('backups.request_timeout_seconds', 60),
            ),
            $this->redactor,
        );
    }
}
