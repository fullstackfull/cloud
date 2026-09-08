<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Destroying a copy of somebody's data takes the archive's own reference typed
 * back.
 *
 * The same device the reinstall, the immediate cancellation and the ownership
 * transfer use, and for the same reason: it is not a lookup, it is evidence
 * that a person read the screen. A boolean would be defaulted by a client
 * library and resent by a retry loop.
 */
final class DeleteBackupRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm_backup_id' => ['required', 'string'],
        ];
    }

    public function confirmation(): string
    {
        return (string) $this->input('confirm_backup_id');
    }
}
