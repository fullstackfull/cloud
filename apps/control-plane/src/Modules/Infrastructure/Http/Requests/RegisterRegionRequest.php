<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A region is the top of the estate and the only object with no parent, which
 * is why it needed a writer before anything below it could have one.
 *
 * The name is translatable because it is shown to customers in the catalogue;
 * everything else on this row is operator-facing and is a plain string.
 */
final class RegisterRegionRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('regions', 'slug')],
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:120'],
            'name.ar' => ['nullable', 'string', 'max:120'],
            // ISO 3166-1 alpha-2, which is what the column is and what tax and
            // currency resolution read.
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'city' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('country')) {
            $this->merge(['country' => strtoupper((string) $this->input('country'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function translatedName(): array
    {
        /** @var array<string, string> $name */
        $name = array_filter(
            (array) $this->input('name', []),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        );

        return $name;
    }
}
