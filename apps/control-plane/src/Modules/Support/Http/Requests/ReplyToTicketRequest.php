<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class ReplyToTicketRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:20000'],
            'attachments' => ['sometimes', 'array', 'max:'.max(1, (int) config('support.attachments.max_per_message', 5))],
            'attachments.*' => ['file', 'max:'.max(1, (int) (((int) config('support.attachments.max_bytes', 10485760)) / 1024))],
        ];
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
}
