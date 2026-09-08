<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A name to look for, and the namespaces to look in.
 *
 * The name itself is checked in the domain, by {@see RegistrableDomain},
 * because what counts as registrable is a registry rule rather than Laravel's
 * idea of a domain — and the same rules have to apply to a name arriving in an
 * order.
 *
 * `also_try` is bounded here as well as in the action. Each entry is a
 * provider call, and a request carrying two hundred of them is the cheapest
 * denial of service against this platform's registrar allowance that exists.
 */
final class SearchDomainsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:253'],

            'also_try' => ['sometimes', 'array', 'max:10'],
            'also_try.*' => ['string', 'max:63'],
        ];
    }
}
