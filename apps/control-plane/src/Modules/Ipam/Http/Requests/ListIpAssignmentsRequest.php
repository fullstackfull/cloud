<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Lynomia\Http\Concerns\BoundsPageSize;

/**
 * Paging for the address list.
 *
 * Note what is not here, because on an IPAM surface the absences are the
 * design: no customer id, no service id, no subnet, no pool, no datacenter and
 * no `status`. Which account's addresses are listed is decided by the
 * acting-customer middleware from the authenticated principal; any of the
 * others in a query string would be either a request to read somebody else's
 * addresses or a way to ask the platform which of its subnets exist.
 *
 * There is no free-text address filter either. A customer holds a handful of
 * addresses, not a routing table, and a filter that accepted a partial address
 * would answer "does the platform have anything matching 203.0.113.%" — a
 * question the customer surface has no reason to be able to answer.
 */
final class ListIpAssignmentsRequest extends FormRequest
{
    use BoundsPageSize;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Validated as an integer but not bounded here; see BoundsPageSize.
            // The ceiling is applied by clamping, so an enormous per_page is
            // answered rather than refused while the query still never sees the
            // number the caller asked for.
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
