<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveDeploymentRequest extends FormRequest
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
            'outcome' => ['required', 'string', 'in:completed,failed'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
