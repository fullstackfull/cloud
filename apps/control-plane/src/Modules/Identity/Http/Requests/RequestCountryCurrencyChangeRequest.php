<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
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

            /*
             * Deliberately NOT checked against the enabled list.
             *
             * This is the change *request* workflow, and its job is to answer
             * a customer who asks. A currency the platform does not price in
             * is a blocker the analysis explains — "nothing is priced in XAF"
             * — on a request an operator can then see and reply to. Refusing
             * it here would replace that explanation with a form error and
             * lose the record that the customer asked at all.
             *
             * Registration is the opposite case and does check the list: there
             * the currency is being set, not requested.
             */
            'currency' => ['required', 'string', 'size:3', 'alpha'],

            'reason' => ['required', 'string', 'min:3', 'max:500'],
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
