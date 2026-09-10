<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Lynomia\Modules\Billing\Domain\Services\BillingCurrencies;
use Lynomia\Modules\Identity\Domain\Enums\CustomerType;

final class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $currencies = app(BillingCurrencies::class);

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            /*
             * Deliberately NOT `Rule::unique`. A validation failure on a taken
             * address is a one-request membership oracle: an attacker learns
             * which of a list of addresses hold accounts here, which is the
             * input to credential stuffing and to convincing phishing. The
             * unique index on the column still holds - RegisterCustomer is
             * where the collision is handled, and it handles it by saying
             * nothing.
             */
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],

            'account_type' => ['sometimes', Rule::enum(CustomerType::class)],
            'company_name' => [
                Rule::requiredIf(fn (): bool => $this->input('account_type') === CustomerType::Organization->value),
                'nullable', 'string', 'max:180',
            ],

            /*
             * Required, and checked against the ISO-3166-1 list the platform
             * accepts.
             *
             * It used to be optional, and an account registered without one
             * was booked in the platform's default currency without anybody
             * saying so — a customer met their currency on their first invoice
             * and changing it afterwards is a support conversation. The
             * country is now the question that decides the money, so it is
             * asked, and an answer the standard does not contain is refused.
             */
            'country' => ['required', 'string', 'size:2', 'alpha', Rule::in($currencies->countries())],

            /*
             * Optional, because the country already implies a recommendation
             * the screen shows before submit; present when the customer
             * overrode it, and then it must be a currency this platform
             * actually bills in. `Rule::in` and not a size check: a browser
             * that rewrites the select in DevTools submits a code that is
             * refused here, rather than one that reaches a customer record.
             */
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha', Rule::in($currencies->enabled())],
            'locale' => ['sometimes', 'string', Rule::in(config('app.supported_locales', ['en']))],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/\A\+?[0-9 ()-]{6,32}\z/'],

            // Explicit acceptance is recorded, not assumed.
            'accepts_terms' => ['accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        /*
         * Case is normalised before validation rather than after it, so the
         * customer sees one message about an unknown country and never a
         * second one about its capitalisation.
         */
        $this->merge(array_filter([
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : null,
            'country' => is_string($this->input('country')) ? strtoupper(trim($this->input('country'))) : null,
            'currency' => is_string($this->input('currency')) ? strtoupper(trim($this->input('currency'))) : null,
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country.required' => __('validation.requests.registration.country_required'),
            'country.in' => __('validation.requests.registration.country_unknown'),
            'currency.in' => __('validation.requests.registration.currency_not_billable', [
                'currencies' => implode(', ', app(BillingCurrencies::class)->enabled()),
            ]),
        ];
    }
}
