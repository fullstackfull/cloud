<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RotateCredentialRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rotates_at' => ['nullable', 'date', 'after:today'],
        ];
    }
}
