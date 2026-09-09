<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CloneWordPressSiteRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'min:3', 'max:253'],
        ];
    }

    public function domain(): string
    {
        return (string) $this->validated('domain');
    }
}
