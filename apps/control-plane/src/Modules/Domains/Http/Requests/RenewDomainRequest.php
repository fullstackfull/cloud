<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Extending a name, at a price the server wrote down.
 *
 * The quote carries the name, the term and the amount. There is nothing else
 * to send, and in particular there is no price: see {@see QuoteDomain}.
 */
final class RenewDomainRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['quote_id' => ['required', 'string', 'ulid']];
    }
}
