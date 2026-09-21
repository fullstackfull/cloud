<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Ipam\Domain\Enums\NetworkPurpose;

final class RegisterNetworkRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('networks', 'slug')],
            'name' => ['required', 'string', 'max:120'],
            'purpose' => ['required', Rule::enum(NetworkPurpose::class)],
            // 802.1Q: 0 and 4095 are reserved.
            'vlan_id' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'bridge' => ['nullable', 'string', 'max:60'],
            /*
             * Accepted, and then decided by the action: management and cluster
             * interconnect never carry customer workloads whatever this says.
             * The rule lives there because it is the same rule the allocator
             * and the placement path depend on, not a display preference.
             */
            'is_customer_facing' => ['sometimes', 'boolean'],
        ];
    }
}
