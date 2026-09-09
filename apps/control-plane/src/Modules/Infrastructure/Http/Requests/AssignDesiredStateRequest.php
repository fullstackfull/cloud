<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignDesiredStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'profile' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            // Validated for shape here and for meaning (declared keys only)
            // in the action, which knows the profile's components.
            'overrides' => ['sometimes', 'array', 'max:40'],
            'overrides.*' => ['nullable'],
        ];
    }
}
