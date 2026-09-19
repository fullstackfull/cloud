<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Whether the platform prepares the next term for this name.
 *
 * A boolean and nothing else, and no idempotency key: this is a setting, not
 * an act. Sending the same value twice leaves the same value, so a retried
 * request cannot double-charge or double-order the way a renewal could.
 */
final class SetAutoRenewRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['auto_renew' => ['required', 'boolean']];
    }
}
