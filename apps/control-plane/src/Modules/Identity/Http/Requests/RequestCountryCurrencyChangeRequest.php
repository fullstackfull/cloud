<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RequestCountryCurrencyChangeRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'country' => ['present', 'nullable', 'string', 'size:2', 'alpha'],
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
