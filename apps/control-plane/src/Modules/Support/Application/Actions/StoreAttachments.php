<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Application\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lynomia\Modules\Support\Domain\Exceptions\TicketRefusedException;
use Lynomia\Modules\Support\Infrastructure\Models\SupportAttachment;
use Lynomia\Modules\Support\Infrastructure\Models\SupportMessage;

/**
 * Puts uploaded files somewhere they cannot hurt anybody.
 *
 * Four rules, and each of them closes a way a support queue becomes a delivery
 * mechanism for an attack on the people reading it:
 *
 *  1. **The type is decided from the bytes.** `UploadedFile::getMimeType()`
 *     reads the file's own content; `getClientMimeType()` reads whatever the
 *     browser said, which is whatever the uploader said. A "screenshot" that
 *     is really an HTML document, served back as `image/png` because the
 *     client claimed so, is stored cross-site scripting.
 *  2. **The list is an allow list**, and SVG is not on it. An SVG is a
 *     document that can carry script.
 *  3. **The stored path is generated.** `original_name` is kept to show and to
 *     name the download and is never part of a path: a filename is
 *     attacker-controlled, and `../../.env` is a filename.
 *  4. **The disk is private.** Nothing here is reachable by URL; downloads go
 *     through an endpoint that checks who is asking.
 */
final readonly class StoreAttachments
{
    /**
     * @param  list<UploadedFile>  $files
     * @return list<SupportAttachment>
     */
    public function execute(SupportMessage $message, array $files): array
    {
        $disk = (string) config('support.attachments.disk', 'local');
        $maxBytes = (int) config('support.attachments.max_bytes', 10 * 1024 * 1024);
        /** @var list<string> $allowed */
        $allowed = (array) config('support.attachments.allowed_mime_types', []);

        $stored = [];

        foreach ($files as $file) {
            $size = (int) $file->getSize();

            if ($size > $maxBytes) {
                throw TicketRefusedException::becauseTheFileIsTooLarge($maxBytes);
            }

            // From the bytes. Never getClientMimeType().
            $type = (string) $file->getMimeType();

            if (! in_array($type, $allowed, strict: true)) {
                throw TicketRefusedException::becauseTheTypeIsNotAllowed($type);
            }

            /*
             * A generated name with no extension taken from the upload. The
             * extension is what a misconfigured web server dispatches on, and
             * the stored file is never served by the web server anyway.
             */
            $path = sprintf('support/%s/%s', $message->ticket_id, (string) Str::ulid());

            Storage::disk($disk)->putFileAs(
                dirname($path),
                $file,
                basename($path),
            );

            /** @var SupportAttachment $attachment */
            $attachment = SupportAttachment::query()->create([
                'message_id' => $message->getKey(),
                'disk' => $disk,
                'path' => $path,
                // Trimmed to a basename and stripped of control characters:
                // it ends up in a Content-Disposition header, where a newline
                // is a header somebody else chose.
                'original_name' => $this->safeName((string) $file->getClientOriginalName()),
                'mime_type' => $type,
                'size_bytes' => $size,
                'checksum' => hash_file('sha256', $file->getRealPath()) ?: '',
            ]);

            $stored[] = $attachment;
        }

        return $stored;
    }

    private function safeName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $flattened = (string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $base);

        return mb_substr(trim($flattened), 0, 180) ?: 'attachment';
    }
}
