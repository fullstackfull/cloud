<?php

declare(strict_types=1);

namespace Lynomia\Http\Concerns;

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
 */
trait ReadsIdempotencyKey
{
    protected function prepareForValidation(): void
    {
        $key = $this->header('Idempotency-Key');

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
            'idempotency_key.required' => 'An Idempotency-Key header is required so a repeated submission cannot run this operation twice.',
            'idempotency_key.min' => 'The Idempotency-Key header must be at least 8 characters.',
            'idempotency_key.max' => 'The Idempotency-Key header must not exceed 128 characters.',
            'idempotency_key.regex' => 'The Idempotency-Key header may contain only letters, digits, dots, colons, hyphens and underscores.',
        ];
    }

    public function idempotencyKey(): string
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return (string) $validated['idempotency_key'];
    }
}
