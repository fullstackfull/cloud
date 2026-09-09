<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Clearing one machine for one destructive piece of work.
 *
 * Both fields required, no defaults. This is the request that makes a wipe
 * possible, and every optional field on it would be one an integration could
 * omit.
 */
final class ClearForReimageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm_name' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
