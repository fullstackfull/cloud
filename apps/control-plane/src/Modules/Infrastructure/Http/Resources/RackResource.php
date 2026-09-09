<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;

/**
 * @mixin Rack
 */
final class RackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'datacenter_id' => $this->datacenter_id,
            'datacenter' => $this->whenLoaded('datacenter', fn (): ?string => $this->datacenter?->slug),
            'name' => $this->name,
            'row' => $this->row,
            'units' => $this->units,
            'power_notes' => $this->power_notes,
            'network_notes' => $this->network_notes,
            'machines' => (int) ($this->machines_count ?? 0),
        ];
    }
}
