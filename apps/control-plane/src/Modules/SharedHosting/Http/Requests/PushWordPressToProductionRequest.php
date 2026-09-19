<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressPushScope;

final class PushWordPressToProductionRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', 'string', Rule::enum(WordPressPushScope::class)],
            'confirmation' => ['required', 'string', 'max:253'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.required' => __('validation.requests.wordpress.push_confirmation_required'),
        ];
    }

    public function scope(): WordPressPushScope
    {
        return WordPressPushScope::from((string) $this->input('scope', WordPressPushScope::Both->value));
    }

    public function confirmation(): string
    {
        return (string) $this->validated('confirmation');
    }
}
