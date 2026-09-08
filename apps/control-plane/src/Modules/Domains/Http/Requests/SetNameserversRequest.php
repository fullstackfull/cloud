<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Where the name should point.
 *
 * Two is the floor and thirteen the ceiling, because that is what registries
 * enforce. The count is checked here so the customer reads a sentence, and
 * again in the action so a caller that is not this request cannot slip past
 * it — a single nameserver resolves right up until that host reboots.
 */
final class SetNameserversRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nameservers' => ['required', 'array', 'min:2', 'max:13'],
            'nameservers.*' => ['required', 'string', 'max:253'],
        ];
    }
}
