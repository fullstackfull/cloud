<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Providers\Domain\DTOs\CatalogueEntry;

/**
 * One thing the platform can be pointed at.
 *
 * The catalogue is what a "register a provider" screen is built from, so it
 * carries the requirements as data: a form that knows an endpoint is needed
 * can ask for one, instead of the operator finding out from a refusal.
 *
 * `testable` is here because it is the difference between a provider that can
 * be proven and one that can only be described. Leaving it out would let the
 * screen imply the platform can verify anything in this list.
 *
 * @mixin CatalogueEntry
 */
final class CatalogueEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'driver' => $this->resource->driver,
            'category' => $this->resource->category->value,
            'summary' => $this->resource->summary,
            'needs_endpoint' => $this->resource->needsEndpoint,
            'needs_credential' => $this->resource->needsCredential,
            'needs_licence' => $this->resource->needsLicence,
            'needs_server' => $this->resource->needsServer(),
            'testable' => $this->additional['testable'] ?? false,
            'available_here' => $this->additional['available_here'] ?? true,
        ];
    }
}
