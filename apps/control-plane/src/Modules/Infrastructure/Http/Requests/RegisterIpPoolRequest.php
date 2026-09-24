<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;

final class RegisterIpPoolRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('ip_pools', 'slug')],
            'name' => ['required', 'string', 'max:120'],
            'ip_version' => ['required', Rule::enum(IpVersion::class)],
            /*
             * The security-relevant field. Validated against the enum the
             * allocator and the placement rule both read, so a form cannot
             * produce a pool that means one thing here and another at runtime.
             */
            'scope' => ['required', Rule::enum(IpPoolScope::class)],
            // How long a released address rests before it is handed out again.
            // Public space carries reputation off-platform and rests longer;
            // the model's own default is a week.
            'quarantine_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ];
    }
}
