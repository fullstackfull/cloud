<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Who the registry should record as owning this name.
 *
 * Only the registrant is offered. Registries take four contact roles and treat
 * three of them as decoration; the registrant is the one that decides disputes
 * and transfers, and offering four fields where one matters invites a customer
 * to fill in three that do nothing.
 */
final class UpdateContactsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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
