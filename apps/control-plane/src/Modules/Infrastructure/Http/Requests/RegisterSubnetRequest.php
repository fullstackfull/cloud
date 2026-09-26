<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately thin.
 *
 * Whether the block parses, which version it is, how long its prefix is and
 * whether the gateway falls inside it are all answered by the Cidr value
 * object in the action — the same one IpAllocator reads the block with. A
 * regex here would be a second, weaker copy of that, and the copy that
 * disagrees is the one that writes a subnet the allocator will not allocate
 * from.
 *
 * ---------------------------------------------------------------------------
 * Why there is no uniqueness rule on `cidr`
 * ---------------------------------------------------------------------------
 *
 * There was one — `Rule::unique('subnets', 'cidr')` — and it was exactly the
 * second, weaker copy the paragraph above warns about, wrong three ways at
 * once:
 *
 *  - too weak: it compared strings, so `203.0.113.0/24` and `203.0.113.0/25`
 *    passed it and one address reached two customers;
 *  - too strong: it was global, so the same RFC 1918 block could not be
 *    registered in a second datacenter, which is a normal estate;
 *  - applied to the unnormalised input: `203.0.113.7/24` passed it, was
 *    normalised by the action to a block the pool already held, and reached
 *    the table's unique index as a 500.
 *
 * Whether a block may coexist with the estate is RegisterSubnet's question,
 * asked of parsed blocks under a lock.
 *
 * What holds this removal in place is a property rather than a count.
 * Restoring the rule reddens exactly the rows that assert one of the two
 * things the action allows on purpose and a global string rule cannot: the
 * same reusable block held by two datacenters, and one block held by two
 * pools where the point is which pool a refusal names. The check, when this
 * is next measured, is that the red set and the set of such rows are the same
 * set — which a row arriving cannot falsify, where a total would.
 *
 * Carrying the total was tried when this was first built, and the total was
 * wrong more than once (the F-33 row of docs/round-2-remediation-ledger.md):
 * once because a leak in another test file had already reddened a row the
 * count then claimed, and once because a new row of the second kind arrived
 * and nobody re-measured. The rule those taught is kept: measure the baseline
 * first — a red row is evidence about a mutation only if the row was green
 * without it.
 */
final class RegisterSubnetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'cidr' => ['required', 'string', 'max:64'],
            'gateway' => ['nullable', 'string', 'max:64'],
            'network_id' => ['nullable', 'string', Rule::exists('networks', 'id')],
        ];
    }
}
