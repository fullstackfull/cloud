<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;

final class ChangeMemberRoleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(CustomerRole::assignableValues())],
        ];
    }

    public function role(): CustomerRole
    {
        return CustomerRole::from((string) $this->input('role'));
    }
}
