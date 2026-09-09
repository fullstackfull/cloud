<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\GpuDevice;

/**
 * @mixin GpuDevice
 */
final class GpuDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->managed_server_id,
            'vendor' => $this->vendor,
            'model' => $this->model,
            'vram_mib' => $this->vram_mib,
            'pci_address' => $this->pci_address,
            'passthrough_mode' => $this->passthrough_mode->value,
            'dedicated' => $this->passthrough_mode->isDedicated(),
            'allocation_state' => $this->allocation_state->value,
            'notes' => $this->notes,
            'registered_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
