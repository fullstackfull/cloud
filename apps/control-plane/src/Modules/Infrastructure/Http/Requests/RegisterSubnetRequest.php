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
            'cidr' => ['required', 'string', 'max:64', Rule::unique('subnets', 'cidr')],
            'gateway' => ['nullable', 'string', 'max:64'],
            'network_id' => ['nullable', 'string', Rule::exists('networks', 'id')],
        ];
    }
}
