<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Backups\Domain\Enums\BackupMode;

/**
 * What a customer may say when asking for a backup.
 *
 * Not much, and that is the point. The datastore, the node, the retention
 * policy and the provider are the platform's decisions — a request that could
 * name a datastore would be a request that could name somebody else's.
 *
 * `mode` is offered because the trade is genuinely the customer's to make: a
 * snapshot keeps the machine running and is only as consistent as the guest
 * made it, and a stop is unambiguous and costs them an outage. Nobody but the
 * person running the workload can weigh that.
 */
final class CreateBackupRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['sometimes', Rule::enum(BackupMode::class)],

            // Free text the customer will read later, in their own list.
            // Length-bounded because it travels to the provider as a note.
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function mode(): BackupMode
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $mode = $validated['mode'] ?? null;

        return is_string($mode) ? BackupMode::from($mode) : BackupMode::Snapshot;
    }

    public function notes(): ?string
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $notes = $validated['notes'] ?? null;

        return is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
    }
}
