<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;

final class BrowseBackupFilesRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'path' => ['sometimes', 'string', 'max:'.BackupPath::MAX_LENGTH],
        ];
    }

    /**
     * Read into the value object, which is where the traversal family is
     * refused — with a 422 that names the reason, not a 500. Named for what
     * it is: the Request base class has its own path(), the URL's.
     */
    public function archivePath(): BackupPath
    {
        return BackupPath::of((string) $this->input('path', '/'));
    }
}
