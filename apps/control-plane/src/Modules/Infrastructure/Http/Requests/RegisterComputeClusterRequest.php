<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Compute\Domain\Enums\ComputeDriver;

final class RegisterComputeClusterRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('compute_clusters', 'slug')],
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', Rule::enum(ComputeDriver::class)],
            /*
             * The endpoint's *shape* is checked here and its *safety* is
             * checked by EndpointPolicy in the action. Splitting them is
             * deliberate: the policy is the one the connection testers and the
             * provider registry already use, and a second copy of "is this
             * address safe to dial" in a form request is a second answer.
             */
            'api_endpoint' => ['nullable', 'string', 'max:255'],
            'verify_tls' => ['sometimes', 'boolean'],
            /*
             * A name in configuration that the secret resolver looks up, never
             * a secret. There is no field on this request that accepts one.
             */
            'credentials_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
