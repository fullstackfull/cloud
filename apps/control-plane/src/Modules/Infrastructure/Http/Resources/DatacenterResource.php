<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;

/**
 * @mixin Datacenter
 */
final class DatacenterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'facility' => $this->facility,
            'region_id' => $this->region_id,
            'region' => $this->whenLoaded('region', fn (): ?string => $this->region?->slug),
            'is_active' => $this->is_active,
            'racks' => (int) ($this->racks_count ?? 0),
            'machines' => (int) ($this->machines_count ?? 0),
        ];
    }
}
