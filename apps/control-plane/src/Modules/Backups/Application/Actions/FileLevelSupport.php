<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Lynomia\Http\Responses\ErrorCatalogue;
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
 * Four things have to be true: the backup's provider implements the
 * file-level interface, the backup is a finished one whose archive the
 * platform knows the name of, the datastore has not read that archive back
 * and found it unreadable (`verified = false`, the same refusal a
 * whole-machine restore makes; `null` is not a verdict and is offered), and
 * the provider can be constructed at all.
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
            // The catalogue's sentence in the request's language, never the exception's.
            return ['supported' => false, 'reason' => ErrorCatalogue::message($e->errorCode(), $e->context(), $e->getMessage())];
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
        $this->assertOpenable($backup);

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

    /**
     * What the row itself says: finished, named, and not found unreadable.
     *
     * Public so {@see RestoreBackupFiles} can ask it again of the row it has
     * locked, which is the read that counts.
     *
     * @throws BackupFileRefusedException
     */
    public function assertOpenable(Backup $backup): void
    {
        if (! $backup->state->isRestorable()) {
            throw BackupFileRefusedException::notAvailable(
                (string) $backup->getKey(),
                sprintf('its state is %s, and only a completed backup can be opened', $backup->state->value),
            );
        }

        if ($backup->verified === false) {
            /*
             * The whole restore refuses this archive for the reason that
             * matters most — it writes an unreadable image over a working
             * machine — and a file restore writes the same data over the files
             * it names. It used to be accepted here (202), and the Files
             * button offered, because only the state was read.
             */
            throw BackupFileRefusedException::notAvailable(
                (string) $backup->getKey(),
                'the datastore read this archive back and it did not come back',
            );
        }

        if (trim((string) $backup->archive_id) === '') {
            throw BackupFileRefusedException::notAvailable(
                (string) $backup->getKey(),
                'the platform never recorded which archive this backup wrote',
            );
        }
    }
}
