<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Giving a domain up takes the domain typed back.
 *
 * The same device the reinstall, the backup deletion and the ownership
 * transfer use, and for the strongest reason of the four: a zone that is gone
 * answers NXDOMAIN for every name under it at once. A boolean would be
 * defaulted by a client library and resent by a retry loop.
 */
final class ReleaseZoneRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm_zone_name' => ['required', 'string'],
        ];
    }

    public function confirmation(): string
    {
        return strtolower(trim((string) $this->input('confirm_zone_name')));
    }
}
