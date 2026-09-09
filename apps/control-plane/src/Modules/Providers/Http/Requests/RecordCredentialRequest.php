<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Lynomia\Modules\Providers\Application\Actions\RecordCredentialReference;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;

/**
 * Declaring where a credential lives.
 *
 * There is no `value` field and no `secret` field, and none is accepted
 * silently either: the request is validated against exactly these keys, so a
 * client that sends a secret by any name is refused before the body is read
 * by anything that could log it.
 */
final class RecordCredentialRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9._-]*$/i', Rule::unique('credential_references', 'name')],
            'purpose' => ['required', 'string', 'max:200'],
            'environment' => ['required', Rule::enum(DeploymentEnvironment::class)],
            'backend' => ['required', Rule::in(RecordCredentialReference::BACKENDS)],
            // The shape is enforced again in the action with a better message;
            // this is the cheap refusal for something obviously not a name.
            'backend_reference' => ['required', 'string', 'max:128'],
            'masked_hint' => ['nullable', 'string', 'max:4'],
            'rotates_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['backend' => $this->input('backend', 'controller_environment')]);
    }

    /**
     * Anything not in rules() is a refusal, not an omission.
     *
     * Laravel's default is to ignore unknown keys, which for most requests is
     * the right default and for this one is not: a `secret` or `password` key
     * arriving here must not be quietly dropped, because it means somebody's
     * client is sending secrets to an endpoint that stores references, and
     * they need to know. Field names only are reported — never the values.
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
                        'backend_reference',
                        sprintf(
                            'Unexpected field(s): %s. This endpoint stores a reference to a secret, never a secret. '
                            .'If a value was sent, rotate it now.',
                            implode(', ', $unexpected),
                        ),
                    );
                }
            },
        ];
    }
}
