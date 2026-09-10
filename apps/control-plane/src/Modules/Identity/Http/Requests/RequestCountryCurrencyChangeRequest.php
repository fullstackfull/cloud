<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Billing\Domain\Services\BillingCurrencies;

final class RequestCountryCurrencyChangeRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $currencies = app(BillingCurrencies::class);

        return [
            /*
             * Checked against the same authoritative list registration uses.
             * A shape check would accept "XX", and a change request naming a
             * country that does not exist wastes an operator's review before
             * being refused anyway.
             */
            'country' => [
                'present', 'nullable', 'string', 'size:2', 'alpha',
                function (string $attribute, mixed $value, callable $fail) use ($currencies): void {
                    if (is_string($value) && $value !== '' && ! $currencies->isKnownCountry($value)) {
                        $fail(__('validation.requests.registration.country_unknown'));
                    }
                },
            ],

            // And the currency must be one the platform actually bills in, for
            // the same reason: the answer is no either way, and it is cheaper
            // and clearer to say so now.
            'currency' => ['required', 'string', 'size:3', 'alpha', Rule::in($currencies->enabled())],

            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency.in' => __('validation.requests.registration.currency_not_billable', [
                'currencies' => implode(', ', app(BillingCurrencies::class)->enabled()),
            ]),
        ];
    }

    public function country(): ?string
    {
        $country = $this->validated('country');

        return is_string($country) && $country !== '' ? strtoupper($country) : null;
    }

    public function currency(): string
    {
        return strtoupper((string) $this->validated('currency'));
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
