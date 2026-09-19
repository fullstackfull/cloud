<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Destroying a copy of somebody's data takes the machine's hostname typed back.
 *
 * The same device the reinstall, the immediate cancellation and the ownership
 * transfer use, and for the same reason: it is not a lookup, it is evidence
 * that a person read the screen. A boolean would be defaulted by a client
 * library and resent by a retry loop.
 *
 * It used to be the archive's own ULID, which is where Wave 3 changed it. A
 * twenty-six character identifier is not evidence that anybody read anything:
 * it is a string nobody can check, copied from one part of the screen to
 * another, and the audit listed it beside two others as an internal identifier
 * standing in for intent. Which backup is being destroyed is settled by the
 * URL and named in the dialogue; what the phrase establishes is that a person
 * meant to destroy a copy of the data on *that machine*.
 */
final class DeleteBackupRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.required' => __('validation.requests.backup.delete_confirmation_required'),
        ];
    }

    public function confirmation(): string
    {
        return (string) $this->validated('confirmation');
    }
}
