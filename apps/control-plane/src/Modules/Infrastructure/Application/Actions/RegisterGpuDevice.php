<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Illuminate\Database\QueryException;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\GpuPassthroughMode;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\GpuDevice;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;

/**
 * An operator recording a GPU they can see in a machine.
 *
 * Recording is not touching: a device may be registered on a machine of any
 * classification, because knowing what is in a chassis is the point of the
 * registry. It COUNTS as capacity only on a machine classified to allow
 * configuration ({@see GpuDevice::scopeCapacity()}), so registering a card in
 * a do_not_touch chassis changes what the platform knows and not what it
 * may sell. No classification is raised here, or anywhere but ClassifyServer.
 *
 * One row per PCI address per machine; a second registration of the same
 * address is refused rather than silently doubling the estate.
 */
final readonly class RegisterGpuDevice
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(
        ManagedServer $server,
        string $vendor,
        string $model,
        int $vramMib,
        string $pciAddress,
        GpuPassthroughMode $mode,
        ?string $notes,
        User $operator,
    ): GpuDevice {
        try {
            return $this->record->execute(
                act: fn (): GpuDevice => GpuDevice::query()->create([
                    'managed_server_id' => $server->getKey(),
                    'vendor' => $vendor,
                    'model' => $model,
                    'vram_mib' => $vramMib,
                    'pci_address' => strtolower($pciAddress),
                    'passthrough_mode' => $mode,
                    'notes' => $notes,
                    'registered_by' => $operator->getKey(),
                ]),
                describe: fn (GpuDevice $device): AuditedAct => new AuditedAct(
                    action: AuditAction::GpuDeviceRegistered,
                    subject: $device,
                    context: [
                        'server' => $server->name,
                        'vendor' => $vendor,
                        'model' => $model,
                        'vram_mib' => $vramMib,
                        'pci_address' => strtolower($pciAddress),
                        'passthrough_mode' => $mode->value,
                        'classification' => $server->safety_class->value,
                        'operator' => $operator->getKey(),
                    ],
                ),
            );
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'gpu_devices_managed_server_id_pci_address_unique')) {
                throw new DeploymentRefused(sprintf('%s already has a device registered at %s.', $server->name, strtolower($pciAddress)), 'gpu_exists');
            }

            throw $e;
        }
    }
}
