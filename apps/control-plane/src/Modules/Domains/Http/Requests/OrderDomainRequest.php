<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Buying the name a quote was written for.
 *
 * There is no name here and no price. The quote id carries both, and it was
 * written by the server — so the only thing this request decides is *which*
 * quote to spend, and that is checked against the acting account.
 *
 * The contacts are here because a registry files a registration against a
 * person, and that person is not necessarily the account holder: a customer
 * buying on behalf of a client registers it to the client. Every field is
 * personal data, stored encrypted, and never published back on any surface but
 * this account's own.
 */
final class OrderDomainRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'string', 'ulid'],

            'registrant' => ['required', 'array'],
            'registrant.name' => ['required', 'string', 'max:255'],
            'registrant.organisation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'registrant.email' => ['required', 'email:rfc', 'max:255'],
            'registrant.phone' => ['required', 'string', 'max:32'],
            'registrant.address_line_one' => ['required', 'string', 'max:255'],
            'registrant.address_line_two' => ['sometimes', 'nullable', 'string', 'max:255'],
            'registrant.city' => ['required', 'string', 'max:120'],
            'registrant.region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'registrant.postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'registrant.country' => ['required', 'string', 'size:2'],
        ];
    }
}
