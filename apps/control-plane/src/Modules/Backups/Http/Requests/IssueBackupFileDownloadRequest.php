<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;

final class IssueBackupFileDownloadRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:'.BackupPath::MAX_LENGTH],
        ];
    }

    public function archivePath(): BackupPath
    {
        return BackupPath::of((string) $this->validated('path'));
    }
}
