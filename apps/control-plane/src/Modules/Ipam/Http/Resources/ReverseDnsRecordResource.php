<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;

/**
 * The PTR a customer asked for, and how far it has got.
 *
 * `last_error` is not here, and its absence is the point of this class. The
 * column holds whatever the DNS provider last said — provider prose, quoting
 * the request the platform sent, which is why the model redacts it before
 * storing it. Redacted is good enough for a support engineer reading the row;
 * it is not a customer-facing document, and a client that displayed it would be
 * showing a customer the shape of the platform's zone API.
 *
 * What the customer gets instead is `status`. `failed` is a complete answer to
 * "did my name take?" and is the one they can act on: change the name, or ask
 * support what the provider objected to.
 *
 * `pending` is honest rather than optimistic. The record is published out of
 * band, so at the moment this response is written nothing has been sent
 * anywhere, and reporting the customer's requested name as live would be a
 * promise made by the wrong half of the system.
 *
 * @mixin ReverseDnsRecord
 */
final class ReverseDnsRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'hostname' => $this->hostname,
            'status' => $this->status->value,
            // Whether the platform is still working on it, asked of the enum
            // that decides, so a client's spinner cannot drift from what the
            // status means here.
            'is_settled' => $this->resource->status->isSettled(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
