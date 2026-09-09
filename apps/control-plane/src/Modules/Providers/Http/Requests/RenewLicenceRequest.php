<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RenewLicenceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expires_on' => ['required', 'date'],
            'renews_on' => ['nullable', 'date'],
            'external_reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
