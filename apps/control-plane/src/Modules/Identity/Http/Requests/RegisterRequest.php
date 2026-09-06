<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Lynomia\Modules\Identity\Domain\Enums\CustomerType;

final class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],

            'account_type' => ['sometimes', Rule::enum(CustomerType::class)],
            'company_name' => [
                Rule::requiredIf(fn (): bool => $this->input('account_type') === CustomerType::Organization->value),
                'nullable', 'string', 'max:180',
            ],

            'country' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'locale' => ['sometimes', 'string', Rule::in(config('app.supported_locales', ['en']))],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/\A\+?[0-9 ()-]{6,32}\z/'],

            // Explicit acceptance is recorded, not assumed.
            'accepts_terms' => ['accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : null,
        ], static fn (mixed $v): bool => $v !== null));
    }
}
