<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;

/**
 * Recording a licence.
 *
 * `external_reference` is an order number or account id — what a person quotes
 * to the vendor's support desk. A licence KEY is not accepted here under any
 * name: if it is sensitive, it is a credential reference, and `credential_id`
 * points at it.
 */
final class RecordLicenceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9._-]*$/i'],
            'licence_type' => ['nullable', 'string', 'max:80'],
            'environment' => ['required', Rule::enum(DeploymentEnvironment::class)],
            'managed_server_id' => ['nullable', 'string', Rule::exists('managed_servers', 'id')],
            'credential_id' => ['nullable', 'string', Rule::exists('credential_references', 'id')],
            'starts_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'renews_on' => ['nullable', 'date'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * A licence key posted under its own name is refused, and the refusal
     * says where keys go.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $unexpected = array_diff(array_keys($this->all()), array_keys($this->rules()));

                if ($unexpected !== []) {
                    $validator->errors()->add(
                        'external_reference',
                        sprintf(
                            'Unexpected field(s): %s. A licence key is a credential reference, not a field on the licence. '
                            .'If a key was sent, rotate it now.',
                            implode(', ', $unexpected),
                        ),
                    );
                }
            },
        ];
    }
}
