<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Provisioning\Domain\Enums\CustomerServiceState;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Provisioning\Infrastructure\Queries\ServiceIdentities;

/**
 * A service as an order reports it: the thing that now exists because this
 * order was paid.
 *
 * Enough to name it and link to it, and no more — the service surface answers
 * everything else. `identity` is the machine's own name and is null while it
 * is still being built, which the screen says rather than filling in.
 *
 * @mixin Service
 */
final class OrderServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => (string) $this->kind,
            'identity' => ServiceIdentities::identityOf($this->resource),

            /*
             * Where the thing lives, as `{kind, id}`. The order's own id is
             * not the machine's: a client that linked from the service id
             * would send a customer to a page for a resource that does not
             * exist.
             */
            'resource' => ServiceIdentities::handleOf($this->resource),
            'state' => CustomerServiceState::for($this->resource->status, false)->value,
            'is_usable' => $this->resource->isUsable(),
        ];
    }
}
