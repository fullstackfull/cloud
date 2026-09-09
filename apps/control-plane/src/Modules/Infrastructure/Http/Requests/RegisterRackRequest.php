<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterRackRequest extends FormRequest
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
            'datacenter_id' => ['required', 'string', Rule::exists('datacenters', 'id')],
            'name' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'row' => ['nullable', 'string', 'max:20'],
            'units' => ['required', 'integer', 'min:1', 'max:60'],
            'power_notes' => ['nullable', 'string', 'max:2000'],
            'network_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
