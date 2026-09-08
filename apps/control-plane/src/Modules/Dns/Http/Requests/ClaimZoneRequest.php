<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Claiming a zone takes a name and nothing else.
 *
 * No `verified` flag, no ownership token, no registrar reference. Every one of
 * those would be a field the platform could not check, and a field that cannot
 * be checked is a field that lies on a screen somewhere. What settles ownership
 * is the delegation the customer makes at their registrar afterwards.
 *
 * The shape is checked here and the *name* is checked in the domain, by
 * DomainName, because the rules there are the platform's rules rather than
 * Laravel's idea of a domain — and a record's name has to go through the same
 * ones.
 */
final class ClaimZoneRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:253'],

            // Optional, and only ever a service this account holds — checked
            // by the controller, which is the only thing that knows who is
            // asking.
            'service_id' => ['sometimes', 'nullable', 'string', 'ulid'],
        ];
    }
}
