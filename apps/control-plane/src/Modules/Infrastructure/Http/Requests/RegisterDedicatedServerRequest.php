<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterDedicatedServerRequest extends FormRequest
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
            'rack_id' => ['nullable', 'string', Rule::exists('racks', 'id')],
            'manufacturer' => ['required', 'string', 'max:120'],
            'model' => ['required', 'string', 'max:120'],
            /*
             * The one field that identifies the physical object. Unique,
             * because two rows with one serial is two rows the platform can
             * reserve separately and one machine to deliver.
             */
            'serial' => ['required', 'string', 'max:120', Rule::unique('dedicated_servers', 'serial')],
            'asset_tag' => ['nullable', 'string', 'max:120'],
            'rack_unit' => ['nullable', 'integer', 'min:1', 'max:60'],
            'height_units' => ['sometimes', 'integer', 'min:1', 'max:20'],
            // Matched against the catalogue's dedicated tiers by the
            // reservation path; a free string here rather than an enum,
            // because the tiers are catalogue rows and not code.
            'hardware_profile' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
