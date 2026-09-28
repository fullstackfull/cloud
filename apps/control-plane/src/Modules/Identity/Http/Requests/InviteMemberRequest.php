<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Http\Rules\LoginAddressAtIntake;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;

/**
 * The two things an invitation needs, and nothing else.
 *
 * The role is validated against the assignable list rather than against the
 * enum, so `owner` is refused by the form as well as by the action. Two places
 * is right here: the form gives the caller a field-level error, and the action
 * gives the same refusal to a console command or a test that never sees a
 * form.
 *
 * There is deliberately no `customer_id`. The account is the one the request
 * is already acting for, resolved once by middleware; accepting it in the body
 * would be a second answer to a question that already has one.
 */
final class InviteMemberRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * The rules every route that takes an address in applies, to the
             * address as it will be stored (LoginAddressAtIntake; see
             * prepareForValidation()). `rfc` and not `dns`: a DNS check
             * inside the request would put a live resolver lookup on the
             * path of an endpoint anyone with the permission can call, which
             * is both a latency problem and a disclosure one — the address
             * being invited would be handed to whatever resolver the host
             * uses, for an address that may never become a customer. An
             * address that does not resolve fails at the only moment that
             * proves anything, which is delivery.
             */
            'email' => LoginAddressAtIntake::rules(),
            'role' => ['required', 'string', Rule::in(CustomerRole::assignableValues())],
        ];
    }

    /**
     * The address is validated as it will be stored: the normalised
     * spelling's length is the one the column holds, and a domain's root
     * label is gone before the email rule reads it.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => LoginAddressAtIntake::asStored($this->input('email'))]);
        }
    }

    public function email(): string
    {
        return LoginAddress::normalise((string) $this->input('email'));
    }

    public function role(): CustomerRole
    {
        return CustomerRole::from((string) $this->input('role'));
    }
}
