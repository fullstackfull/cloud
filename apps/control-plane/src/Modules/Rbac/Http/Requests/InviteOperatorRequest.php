<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Rbac\Application\Actions\InviteOperator;

final class InviteOperatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'string', 'email', 'max:255',
                /*
                 * An address that already holds a staff role is refused rather
                 * than quietly re-roled: this endpoint reads as "add an
                 * operator", and using it to silently replace somebody's
                 * authority would be a role change nobody asked for. The role
                 * endpoint is where that is done, and it is audited as such.
                 */
                function (string $attribute, mixed $value, callable $fail): void {
                    if (is_string($value) && InviteOperator::alreadyAnOperator($value)) {
                        $fail('That address already belongs to an operator. Change their roles instead.');
                    }
                },
            ],
            'name' => ['required', 'string', 'max:255'],
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::in(ChangeOperatorRolesRequest::assignable())],
        ];
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        /** @var list<string> $roles */
        $roles = array_values(array_unique($this->input('roles', [])));

        return $roles;
    }
}
