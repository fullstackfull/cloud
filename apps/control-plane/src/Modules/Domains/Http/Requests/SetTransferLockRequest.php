<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The lock that stops a name being taken.
 *
 * A boolean and nothing else. Unlocking is the risky direction and it is not
 * made harder here: the customer owns the name, and a platform that puts
 * friction in front of leaving has stopped competing on being worth staying
 * with. It is audited instead.
 */
final class SetTransferLockRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['locked' => ['required', 'boolean']];
    }
}
