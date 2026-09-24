<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;

final class RegisterHostingNodeRequest extends FormRequest
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
            'datacenter_id' => ['required', 'string', Rule::exists('datacenters', 'id')],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('hosting_nodes', 'slug')],
            'hostname' => ['required', 'string', 'max:255', Rule::unique('hosting_nodes', 'hostname')],
            'panel' => ['required', Rule::enum(HostingPanel::class)],
            'api_endpoint' => ['nullable', 'string', 'max:255'],
            'verify_tls' => ['sometimes', 'boolean'],
            'credentials_reference' => ['nullable', 'string', 'max:255'],
            /*
             * The ceiling an operator sets, not a count. `account_count` is
             * the platform's own tally and is not accepted from a request: a
             * node that arrives claiming to hold accounts it does not hold is
             * a node the placement path will refuse to fill.
             */
            'max_accounts' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
