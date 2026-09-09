<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;

/**
 * Declaring a provider account.
 *
 * The driver is validated for shape only. Whether it is a driver this build
 * actually has is a question for the catalogue, and answering it here would
 * mean two lists of drivers that could disagree — the one people edit and the
 * one that decides.
 *
 * The endpoint is deliberately not validated as a URL. Several of these are
 * host:port pairs for a BMC on a management network, and a rule demanding a
 * scheme would refuse the correct value. What the endpoint may point AT is a
 * separate concern from what it looks like, and belongs where the request is
 * actually made rather than in a form rule that a queued job never runs.
 */
final class RegisterProviderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9._-]*$/i', Rule::unique('provider_instances', 'name')],
            'driver' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'category' => ['required', Rule::enum(ProviderCategory::class)],
            'environment' => ['required', Rule::enum(DeploymentEnvironment::class)],

            'endpoint' => ['nullable', 'string', 'max:255'],
            'managed_server_id' => ['nullable', 'string', Rule::exists('managed_servers', 'id')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
