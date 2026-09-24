<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What an operator must say to map a hosting package.
 *
 * `panel_package_name` is printable ASCII without whitespace for the same
 * reason a template's provider reference is: it is interpolated into a request
 * to the panel, and a value with a newline in it is two lines in a log and an
 * ambiguous call.
 *
 * Every quota is nullable, and null means the model's own "unlimited" rather
 * than zero. A package that granted zero disk would be an account that cannot
 * hold a file, recorded as configured.
 */
final class MapHostingPackageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'panel_package_name' => ['required', 'string', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
            'plan_id' => ['nullable', 'string', Rule::exists('plans', 'id')->whereNull('deleted_at')],

            'disk_quota_mib' => ['nullable', 'integer', 'min:1'],
            'bandwidth_quota_mib' => ['nullable', 'integer', 'min:1'],
            'max_addon_domains' => ['nullable', 'integer', 'min:0'],
            'max_subdomains' => ['nullable', 'integer', 'min:0'],
            'max_databases' => ['nullable', 'integer', 'min:0'],
            'max_email_accounts' => ['nullable', 'integer', 'min:0'],

            'cpu_limit_percent' => ['nullable', 'integer', 'min:1'],
            'memory_limit_mib' => ['nullable', 'integer', 'min:1'],
            'io_limit_kbps' => ['nullable', 'integer', 'min:1'],
            'process_limit' => ['nullable', 'integer', 'min:1'],
            'entry_process_limit' => ['nullable', 'integer', 'min:1'],

            'is_active' => ['required', 'boolean'],
        ];
    }
}
