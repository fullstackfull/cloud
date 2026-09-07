<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Lynomia\Http\Concerns\BoundsPageSize;

/**
 * Paging for the machine list.
 *
 * Note what is not here: no customer id, no account id, no datacenter, no rack
 * and no server id. Which account's machines are listed is decided by the
 * acting-customer middleware, and a query string naming any of the others
 * would be either a request to read somebody else's estate or a probe of where
 * the platform's hardware physically lives.
 *
 * There is no `status` filter either, and its absence is a decision rather
 * than an omission: a customer with dedicated servers has a handful of them,
 * not a fleet, so a filter would add a second vocabulary to keep in step with
 * the resource for no page a client cannot render itself.
 */
final class ListDedicatedServersRequest extends FormRequest
{
    use BoundsPageSize;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Validated as an integer but not bounded here; see BoundsPageSize.
            // The ceiling is applied by clamping so that an enormous per_page
            // is answered rather than refused, while the query is still never
            // handed the number the caller asked for.
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
