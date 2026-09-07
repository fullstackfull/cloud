<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Http\Concerns\BoundsPageSize;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;

/**
 * Paging and one filter.
 *
 * The filter is on state, because the question a customer arrives with is
 * almost always "is there one I can restore from" — and the answer to that is
 * a state, not a date.
 */
final class ListBackupsRequest extends FormRequest
{
    use BoundsPageSize;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'state' => ['sometimes', Rule::enum(BackupState::class)],
        ];
    }

    public function state(): ?BackupState
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $state = $validated['state'] ?? null;

        return is_string($state) ? BackupState::from($state) : null;
    }
}
