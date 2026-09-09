<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One field, required: why. Revoking blocks everything that used the
 * credential, and the reason is the first thing the next person reads.
 */
final class RevokeCredentialRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
