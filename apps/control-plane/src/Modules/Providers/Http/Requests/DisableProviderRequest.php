<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Switching a provider off.
 *
 * One required field, and it is the reason. Turning a provider off stops new
 * work reaching it, which is a change somebody will notice as a queue that is
 * not draining — and the first question then is whether it was deliberate.
 */
final class DisableProviderRequest extends FormRequest
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
