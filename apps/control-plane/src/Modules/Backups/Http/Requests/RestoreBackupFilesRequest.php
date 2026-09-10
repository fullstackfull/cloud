<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;

final class RestoreBackupFilesRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'paths' => ['required', 'array', 'min:1', 'max:'.max(1, (int) config('backups.file_restore_max_paths', 50))],
            'paths.*' => ['required', 'string', 'max:'.BackupPath::MAX_LENGTH],
            'confirmation' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.required' => __('validation.requests.backup.file_restore_confirmation_required'),
        ];
    }

    /**
     * @return list<BackupPath>
     */
    public function paths(): array
    {
        /** @var list<string> $raw */
        $raw = $this->validated('paths');

        return array_map(static fn (string $p): BackupPath => BackupPath::of($p), $raw);
    }

    public function confirmation(): string
    {
        return (string) $this->validated('confirmation');
    }
}
