<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Lynomia\Modules\Ipam\Application\Actions\SetReverseDns;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Hostname;

/**
 * One hostname, and nothing else.
 *
 * The hostname is customer-supplied and ends up in a request to a DNS provider,
 * so it is checked here against the same rules {@see Hostname} enforces — the
 * form request exists to turn a bad name into a 422 that names the field, not
 * to be the only thing standing between a customer's string and a zone API.
 * The action validates again; see {@see SetReverseDns}.
 *
 * The address is not in the body. It is the assignment in the path, resolved
 * through the acting customer's own relation — an address or an assignment id
 * in the body would be a second, unscoped way to name a target, and on this
 * endpoint the target is a record published to the internet under somebody's
 * name.
 *
 * There is no `status` field, no `ttl`, and no way to ask for a record type
 * other than PTR. Each of those is a knob on the platform's zone, not on the
 * customer's address.
 */
final class SetReverseDnsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hostname' => [
                'required',
                'string',
                // A cheap length bound before the expensive rules, so an
                // enormous body is rejected without being parsed into labels.
                'max:'.Hostname::MAX_LENGTH,
            ],
        ];
    }

    /**
     * The hostname rules themselves, run only once the field is present and a
     * string — so a client sending an array or an integer gets "must be a
     * string" rather than a confusing complaint about labels.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hostname = $this->input('hostname');

            if (! is_string($hostname) || $validator->errors()->has('hostname')) {
                return;
            }

            if (! Hostname::isValid($hostname)) {
                $validator->errors()->add(
                    'hostname',
                    'Enter a fully qualified hostname, for example mail.example.com. '
                    .'Letters, digits and hyphens only, and not an IP address.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hostname.required' => 'Name the hostname this address should resolve back to.',
        ];
    }

    public function hostname(): string
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return (string) $validated['hostname'];
    }
}
