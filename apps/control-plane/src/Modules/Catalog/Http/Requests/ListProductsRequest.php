<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Http\Concerns\BoundsPageSize;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;

/**
 * The query string of the catalogue listing.
 *
 * Deliberately tiny. There is no customer_id, no currency and no locale here:
 * the acting customer comes from the middleware and the currency from that
 * customer, and a currency accepted from the query string would be a request
 * to be quoted somebody else's price list.
 *
 * `kind` is nullable as well as optional. The framework converts an empty
 * query value to null before validation, so `?kind=` — which is what a form
 * sends for "any kind" — would otherwise fail a bare `string` rule and answer
 * an unfiltered browse with a 422.
 *
 * per_page is validated as an integer but deliberately not bounded here; the
 * clamp in BoundsPageSize, and the one in the action behind it, are what
 * enforce the ceiling.
 */
final class ListProductsRequest extends FormRequest
{
    use BoundsPageSize;

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
            'kind' => ['sometimes', 'nullable', 'string', Rule::enum(ProductKind::class)],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * Read from the validated data rather than from the raw query string, so
     * the value the query is built from is the one the rules just passed.
     */
    public function kind(): ?ProductKind
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $kind = $validated['kind'] ?? null;

        return is_string($kind) && $kind !== '' ? ProductKind::from($kind) : null;
    }
}
