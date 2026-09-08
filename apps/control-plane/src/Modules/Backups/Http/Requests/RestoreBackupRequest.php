<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The typed confirmation a restore needs.
 *
 * Validated as present and a string here, and compared against the machine's
 * hostname in the action rather than by a validation rule. The comparison is
 * the domain's decision — it uses hash_equals and refuses to case-fold — and
 * putting it in a rule would let a future caller reach the action without it.
 */
final class RestoreBackupRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
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
            'confirmation.required' => 'Type the machine\'s hostname to confirm. A restore replaces every disk on it.',
        ];
    }

    public function confirmation(): string
    {
        return (string) $this->validated('confirmation');
    }
}
