<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One namespace's answer for one name.
 *
 * `availability` carries all five answers, `unknown` among them, and the
 * screen is required to render it as its own thing rather than folding it into
 * yes or no. Both foldings are defects that cost somebody money.
 *
 * `price_minor` is nullable, and it is null exactly when the platform will not
 * commit to a number — an unavailable name, a namespace closed to new
 * registrations, a premium name whose registry gave no price. A zero here
 * would read as free.
 *
 * Nothing on this payload is authoritative. What the platform honours is a
 * quote row, which is written when the customer asks for one.
 *
 * The fields are named one by one rather than passed through, so that the
 * payload has a single written-down shape — one the API description is
 * checked against.
 *
 * @property array{name: string, tld: string, availability: string, is_orderable: bool, premium: bool, currency: ?string, price_minor: ?int, term_years: int} $resource
 */
final class DomainSearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource['name'],
            'tld' => $this->resource['tld'],
            'availability' => $this->resource['availability'],
            'is_orderable' => $this->resource['is_orderable'],
            'premium' => $this->resource['premium'],
            'currency' => $this->resource['currency'],
            'price_minor' => $this->resource['price_minor'],
            'term_years' => $this->resource['term_years'],
        ];
    }
}
