<?php

declare(strict_types=1);

namespace Lynomia\Http\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Lynomia\Modules\Shared\Domain\Exceptions\IdempotencyKeyRejectedException;

/**
 * The idempotency key is taken from the `Idempotency-Key` header and from
 * nowhere else.
 *
 * Merging it over the input means a body field of the same name cannot win.
 * Two sources for one key is two answers to "is this the same request?", and
 * on this module's surface the wrong answer reinstalls a machine twice.
 *
 * The key is required, never generated. A key the server invents is unique per
 * request, which makes every retry a new operation — precisely the failure the
 * key exists to prevent.
 *
 * A missing or malformed header is refused as its own error rather than as a
 * validation failure: the key is not a form field, and an error that sends a
 * customer looking for a highlighted box that does not exist is worse than no
 * error. See {@see IdempotencyKeyRejectedException}.
 */
trait ReadsIdempotencyKey
{
    protected function prepareForValidation(): void
    {
        $key = $this->header(IdempotencyKeyRejectedException::HEADER);

        $this->merge([
            'idempotency_key' => is_string($key) ? trim($key) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function idempotencyKeyRules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/\A[A-Za-z0-9._:\-]+\z/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function idempotencyKeyMessages(): array
    {
        return [
            'idempotency_key.required' => __('validation.requests.idempotency_key.required'),
            'idempotency_key.min' => __('validation.requests.idempotency_key.min'),
            'idempotency_key.max' => __('validation.requests.idempotency_key.max'),
            'idempotency_key.regex' => __('validation.requests.idempotency_key.regex'),
        ];
    }

    /**
     * The header's own failure takes precedence over the body's.
     *
     * A request with no key and a bad body is a request from a client that is
     * not speaking this API's contract, and the contract error is the one to
     * fix first. Everything else falls through to the ordinary 422 with its
     * field list.
     */
    protected function failedValidation(Validator $validator): void
    {
        $reason = $validator->errors()->first('idempotency_key');

        if ($reason !== '') {
            throw IdempotencyKeyRejectedException::because($reason);
        }

        parent::failedValidation($validator);
    }

    public function idempotencyKey(): string
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return (string) $validated['idempotency_key'];
    }
}
