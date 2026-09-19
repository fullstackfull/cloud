<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Backups\Application\Actions\BrowseBackupFiles;
use Lynomia\Modules\Backups\Application\Actions\IssueBackupFileDownload;
use Lynomia\Modules\Backups\Application\Actions\RestoreBackupFiles;
use Lynomia\Modules\Backups\Application\Actions\ServeBackupFileDownload;
use Lynomia\Modules\Backups\Http\Requests\BrowseBackupFilesRequest;
use Lynomia\Modules\Backups\Http\Requests\IssueBackupFileDownloadRequest;
use Lynomia\Modules\Backups\Http\Requests\RestoreBackupFilesRequest;
use Lynomia\Modules\Backups\Http\Resources\BackupFileRestoreResource;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Models\BackupFileRestore;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Vps\Infrastructure\Queries\CustomerVirtualMachines;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files out of a backup: browse, download one, put some back.
 *
 * Everything here is `service.manage`, including the listing. A backup's
 * contents are the customer's data at rest, and reading them is not the
 * same act as reading the row that says a backup exists.
 *
 * Nothing in any response names where an archive lives. Paths are the
 * archive's own; the datastore, the node and the provider's handle on the
 * archive stay on the row, where the operator's screens can see them.
 */
final class BackupFileController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $acting,
        private readonly BrowseBackupFiles $browse,
        private readonly IssueBackupFileDownload $issue,
        private readonly ServeBackupFileDownload $serve,
        private readonly RestoreBackupFiles $restore,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->acting;
    }

    public function index(BrowseBackupFilesRequest $request, string $vm, string $backup): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $row = $this->backup($vm, $backup);

        return response()->json(['data' => $this->browse->execute($row, $request->archivePath())->toArray()]);
    }

    /**
     * Mint a link. The token appears here, once, inside the URL, and never
     * again: the row stores its hash.
     */
    public function download(IssueBackupFileDownloadRequest $request, string $vm, string $backup): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $row = $this->backup($vm, $backup);

        $issued = $this->issue->execute($row, $request->archivePath(), $request->user()?->getAuthIdentifier());

        return response()->json([
            'data' => [
                'id' => (string) $issued['download']->getKey(),
                'path' => $issued['download']->path,
                'expires_at' => $issued['download']->expires_at->toIso8601String(),
                'url' => route('api.v1.backups.downloads.show', ['token' => $issued['token']], absolute: false),
            ],
        ], 201);
    }

    /**
     * Follow a link. Still behind the session and the account: a link is a
     * second factor on a download, not a way round the first.
     */
    public function fetch(Request $request, string $token): StreamedResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $served = $this->serve->execute($token, $this->acting->id());

        app(RecordAuditEntry::class)->execute(
            action: AuditAction::BackupFileDownloaded,
            subject: $served['backup'],
            customerId: $served['backup']->customer_id,
            context: [
                'service_id' => $served['backup']->service_id,
                'path' => $served['download']->path,
                'size_bytes' => $served['content']->sizeBytes,
            ],
        );

        $stream = $served['content']->stream;

        return response()->streamDownload(
            static function () use ($stream): void {
                while (! feof($stream)) {
                    $chunk = fread($stream, 65_536);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    echo $chunk;
                }

                fclose($stream);
            },
            $served['content']->name,
            [
                // Octet-stream whatever the file is: a browser must save it,
                // not render a customer's HTML inside the platform's origin.
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) $served['content']->sizeBytes,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control' => 'no-store',
            ],
        );
    }

    public function restore(RestoreBackupFilesRequest $request, string $vm, string $backup): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $machine = $this->machine($vm);
        $row = $this->scopedBackup($machine, $backup);

        $restored = $this->restore->execute(
            backup: $row,
            machine: $machine,
            paths: $request->paths(),
            confirmation: $request->confirmation(),
            userId: $request->user()?->getAuthIdentifier(),
        );

        app(RecordAuditEntry::class)->execute(
            action: AuditAction::BackupFilesRestored,
            subject: $restored,
            customerId: $restored->customer_id,
            context: [
                'service_id' => $restored->service_id,
                'virtual_machine_id' => $restored->virtual_machine_id,
                'backup_id' => $restored->backup_id,
                'hostname' => $machine->hostname,
                'paths' => $restored->paths,
                'state' => $restored->state->value,
            ],
        );

        return (new BackupFileRestoreResource($restored))->response()->setStatusCode(202);
    }

    public function restores(Request $request, string $vm, string $backup): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $row = $this->backup($vm, $backup);

        $rows = BackupFileRestore::query()
            ->where('backup_id', $row->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => BackupFileRestoreResource::collection($rows)]);
    }

    private function backup(string $vm, string $backup): Backup
    {
        return $this->scopedBackup($this->machine($vm), $backup);
    }

    private function scopedBackup(VirtualMachine $machine, string $backup): Backup
    {
        /** @var Backup $row */
        $row = Backup::query()
            ->where('virtual_machine_id', $machine->getKey())
            ->whereKey($backup)
            ->firstOrFail();

        return $row;
    }

    private function machine(string $id): VirtualMachine
    {
        /** @var VirtualMachine $machine */
        $machine = CustomerVirtualMachines::of($this->acting->get())
            ->with('service')
            ->whereKey($id)
            ->firstOrFail();

        return $machine;
    }
}
