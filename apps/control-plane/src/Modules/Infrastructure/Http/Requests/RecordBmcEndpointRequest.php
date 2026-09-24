<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;

/**
 * Note what this request does NOT accept: a password.
 *
 * A BMC credential opens a path to power, media and reinstall on a physical
 * machine. It is held by reference — a name the secret resolver looks up — and
 * there is no field here, and no column on the model, that takes a value.
 * `username` is the account name, which is not a secret and is on the row
 * already so that an operator can see which account is in use.
 */
final class RecordBmcEndpointRequest extends FormRequest
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
            'protocol' => ['required', Rule::enum(BmcProtocol::class)],
            // Safety is EndpointPolicy's answer, in the action, using the same
            // rule the connection testers use.
            'address' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:120'],
            'verify_tls' => ['sometimes', 'boolean'],
            'credentials_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
