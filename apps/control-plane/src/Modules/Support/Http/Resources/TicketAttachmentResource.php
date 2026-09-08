<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Support\Infrastructure\Models\SupportAttachment;

/**
 * A file on a message.
 *
 * **No path and no disk.** Where the platform keeps a file is not a customer's
 * business, and a path in a response is a path somebody will eventually try to
 * request. The download goes through an endpoint that takes the attachment's
 * id and checks who is asking.
 *
 * @mixin SupportAttachment
 */
final class TicketAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SupportAttachment $attachment */
        $attachment = $this->resource;

        return [
            'id' => (string) $attachment->getKey(),
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
        ];
    }
}
