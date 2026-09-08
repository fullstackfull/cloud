<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;

/**
 * Ask the platform what it will charge, and for what.
 *
 * There is no price field, and there will not be one. The amount is computed
 * on the server, written to a row, and referred to afterwards by id — see
 * {@see QuoteDomain} for what that defends against.
 *
 * The term is bounded here at ten years because no registry sells more, and
 * the TLD's own maximum is checked afterwards against the catalogue.
 */
final class QuoteDomainRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:253'],
            'operation' => ['required', Rule::enum(DomainOperationKind::class)],
            'term_years' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }
}
