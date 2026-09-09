<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;

/**
 * Changing what an operator has agreed we may do to a machine.
 *
 * The reason is required here as well as in the action. Validating it at the
 * edge gives the operator a field-level error instead of a 500, and the action
 * checks it again because the action is what other callers — a console command,
 * a future importer — will reach for.
 */
final class ClassifyServerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'safety_class' => ['required', Rule::enum(SafetyClass::class)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            // Required only for the destructive rung, and compared against the
            // machine's real name by the action rather than here — the action
            // has the machine, and a check that needs the subject belongs where
            // the subject is.
            'confirm_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
