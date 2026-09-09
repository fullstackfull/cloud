<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A quote id and nothing else. The price a customer pays to recover a name
 * is the quote's, written from the catalogue when they asked; nothing about
 * money crosses the wire here.
 */
final class RedeemDomainRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['quote_id' => ['required', 'string', 'ulid']];
    }
}
