<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightMode;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightScope;

/**
 * What the Control Center is allowed to ask for.
 *
 * `mode` is required and has no default, in the HTTP contract as well as in
 * the service. A client that could omit it would be a client whose author
 * decided by accident whether real providers get dialled.
 */
final class RunPreflightRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(PreflightMode::class)],
            'scope' => ['required', Rule::enum(PreflightScope::class)],
            /*
             * A name an operator typed or an id the screen already held. It is
             * matched against rows and never interpolated into a query, and it
             * never reaches a provider: the endpoint a preflight dials comes
             * from the provider row, not from the request.
             */
            'target' => ['nullable', 'string', 'max:255'],
        ];
    }
}
