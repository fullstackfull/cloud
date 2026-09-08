<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Support\Domain\Enums\TicketCategory;
use Lynomia\Modules\Support\Domain\Enums\TicketPriority;

/**
 * What opening a ticket takes.
 *
 * `priority` is validated against the customer-selectable list rather than the
 * whole enum: `urgent` is what pages somebody out of hours, and a value a
 * customer can select for themselves stops meaning anything within a month.
 *
 * The `service_id` and `invoice_id` are validated for shape here and for
 * *ownership* in the controller. Shape alone would let a customer attach their
 * ticket to a machine belonging to somebody else — which is not access to that
 * machine, but it is a confirmation that the id names a real one.
 */
final class OpenTicketRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'body' => ['required', 'string', 'min:3', 'max:20000'],
            'category' => ['required', 'string', Rule::in(TicketCategory::values())],
            'priority' => ['required', 'string', Rule::in(TicketPriority::customerSelectableValues())],
            'service_id' => ['nullable', 'string', 'ulid'],
            'invoice_id' => ['nullable', 'string', 'ulid'],
            'attachments' => ['sometimes', 'array', 'max:'.self::maxAttachments()],
            // `file` and a byte ceiling here; the type is decided from the
            // bytes in StoreAttachments, because a validator that trusted the
            // client's mime type would be trusting the uploader.
            'attachments.*' => ['file', 'max:'.self::maxKilobytes()],
        ];
    }

    public function category(): TicketCategory
    {
        return TicketCategory::from((string) $this->input('category'));
    }

    public function priority(): TicketPriority
    {
        return TicketPriority::from((string) $this->input('priority'));
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter(
            (array) $this->file('attachments', []),
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));

        return $files;
    }

    private static function maxAttachments(): int
    {
        return max(1, (int) config('support.attachments.max_per_message', 5));
    }

    private static function maxKilobytes(): int
    {
        return max(1, (int) (((int) config('support.attachments.max_bytes', 10485760)) / 1024));
    }
}
