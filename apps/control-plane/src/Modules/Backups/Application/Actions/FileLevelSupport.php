<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Lynomia\Modules\Backups\Domain\Contracts\FileLevelBackupProvider;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupFileRefusedException;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Throwable;

/**
 * Whether one backup's files can be opened, and if not, why not — asked
 * once, answered the same way on every surface.
 *
 * Three things have to be true: the backup's provider implements the
 * file-level interface, the backup is a finished one whose archive the
 * platform knows the name of, and the provider can be constructed at all.
 * The screen shows the answer as a disabled button with the reason; the
 * API refuses with the same reason. Nothing is offered on hope.
 */
final readonly class FileLevelSupport
{
    public function __construct(
        private BackupProviderFactory $providers,
    ) {}

    /**
     * @return array{supported: bool, reason: ?string}
     */
    public function describe(Backup $backup): array
    {
        try {
            $this->provider($backup);
        } catch (BackupFileRefusedException $e) {
            return ['supported' => false, 'reason' => $e->getMessage()];
        }

        return ['supported' => true, 'reason' => null];
    }

    /**
     * The provider, proven able to open files, or the refusal that says why
     * it cannot.
     *
     * @throws BackupFileRefusedException
     */
    public function provider(Backup $backup): FileLevelBackupProvider
    {
        if (! $backup->state->isRestorable()) {
            throw BackupFileRefusedException::notAvailable(
                (string) $backup->getKey(),
                sprintf('its state is %s, and only a completed backup can be opened', $backup->state->value),
            );
        }

        if (trim((string) $backup->archive_id) === '') {
            throw BackupFileRefusedException::notAvailable(
                (string) $backup->getKey(),
                'the platform never recorded which archive this backup wrote',
            );
        }

        /** @var ?ComputeCluster $cluster */
        $cluster = $backup->cluster()->first();

        if ($cluster === null) {
            throw BackupFileRefusedException::notAvailable((string) $backup->getKey(), 'the machine is not attached to a cluster');
        }

        try {
            $provider = $this->providers->for($cluster);
        } catch (Throwable) {
            // Missing credentials, an unknown driver: the reason is the
            // operator's, and the customer gets the same sentence as for a
            // provider that cannot.
            throw BackupFileRefusedException::unsupported((string) $backup->provider);
        }

        if (! $provider instanceof FileLevelBackupProvider) {
            throw BackupFileRefusedException::unsupported($provider->name());
        }

        return $provider;
    }
}
