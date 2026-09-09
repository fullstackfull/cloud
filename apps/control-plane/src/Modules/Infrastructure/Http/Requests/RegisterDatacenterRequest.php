<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterDatacenterRequest extends FormRequest
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
            'region_id' => ['required', 'string', Rule::exists('regions', 'id')],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('datacenters', 'slug')],
            'name' => ['required', 'string', 'max:120'],
            'facility' => ['nullable', 'string', 'max:120'],
        ];
    }
}
