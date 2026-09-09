<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApprovePlanRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            // The machine's name, typed, when the plan is destructive. The
            // controller decides whether it is required; the request only
            // shapes it.
            'confirm_name' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
