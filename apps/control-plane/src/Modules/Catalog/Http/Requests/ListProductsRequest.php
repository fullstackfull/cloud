<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;

/**
 * The query string of the catalogue listing.
 *
 * Deliberately tiny. There is no customer_id, no currency and no locale here:
 * the acting customer comes from the middleware and the currency from that
 * customer, and a currency accepted from the query string would be a request
 * to be quoted somebody else's price list.
 *
 * per_page has no maximum rule because the ceiling is applied in the
 * controller instead: a caller asking for 100000 rows gets 100, not a 422.
 * Refusing would break paging clients that pass a large page size on purpose,
 * while an unbounded page size is how one request reads the whole table.
 */
final class ListProductsRequest extends FormRequest
{
    /**
     * Authorisation is the route's middleware: authenticated, verified and
     * resolved to a customer account. The catalogue itself is the same for
     * every customer, so there is nothing further to authorise here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'string', Rule::enum(ProductKind::class)],
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function kind(): ?ProductKind
    {
        $kind = $this->query('kind');

        return is_string($kind) ? ProductKind::tryFrom($kind) : null;
    }
}
