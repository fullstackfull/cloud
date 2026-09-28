<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Http\Rules\LoginAddressAtIntake;
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
                // Applied to the address as it will be stored; see
                // prepareForValidation().
                ...LoginAddressAtIntake::rules(),
                /*
                 * An address that already holds a staff role is refused rather
                 * than quietly re-roled: this endpoint reads as "add an
                 * operator", and using it to silently replace somebody's
                 * authority would be a role change nobody asked for. The role
                 * endpoint is where that is done, and it is audited as such.
                 * Read without a lock; InviteOperator asks again under one and
                 * refuses with the same words.
                 */
                function (string $attribute, mixed $value, callable $fail): void {
                    if (is_string($value) && InviteOperator::alreadyAnOperator($value)) {
                        $fail(InviteOperator::THE_ADDRESS_IS_AN_OPERATORS);
                    }
                },
            ],
            'name' => ['required', 'string', 'max:255'],
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::in(ChangeOperatorRolesRequest::assignable())],
        ];
    }

    /**
     * The address is validated as it will be stored (LoginAddressAtIntake):
     * it used to be validated as typed, so `ops@EXAMPLE.com。` passed and was
     * stored `ops@example.com.`, and an address that grows when lowercased
     * passed `max:255` and overflowed the column (B9-1, X9-2).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => LoginAddressAtIntake::asStored($this->input('email'))]);
        }
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
