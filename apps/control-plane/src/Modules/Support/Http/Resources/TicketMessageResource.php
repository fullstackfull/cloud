<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Support\Domain\Enums\MessageAuthorKind;
use Lynomia\Modules\Support\Infrastructure\Models\SupportMessage;

/**
 * One message in a thread.
 *
 * The author is a name and a side. An operator's real name is shown because a
 * conversation with an anonymous queue is a worse conversation; their user id
 * is not, because it buys the reader nothing.
 *
 * @mixin SupportMessage
 */
final class TicketMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SupportMessage $message */
        $message = $this->resource;

        return [
            'id' => (string) $message->getKey(),
            'author' => $message->author_kind === MessageAuthorKind::System
                ? null
                : $message->author?->name,
            'author_kind' => $message->author_kind->value,
            'body' => $message->body,
            'is_internal_note' => $message->is_internal_note,
            'attachments' => TicketAttachmentResource::collection(
                $message->relationLoaded('attachments') ? $message->getRelation('attachments') : [],
            ),
            'created_at' => $message->created_at->toIso8601String(),
        ];
    }
}
