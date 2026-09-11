<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Handing an account over takes the successor's membership id and the
 * account's own name typed back.
 *
 * The confirmation is the same device the irreversible cancellation and the
 * reinstall use: a value the caller can only supply by having read the screen.
 * Ownership transfer has no undo that does not depend on the goodwill of the
 * person who now owns the account, which puts it in the same class.
 *
 * **It used to be the account's ULID.** Twenty-six characters of Crockford
 * base32 is unique, which is the only argument for it, and it fails at the one
 * job a typed confirmation has: making the person stop and recognise what they
 * are about to give away. Nobody reads a ULID; they copy it. The account's own
 * display name is the thing the owner would say out loud if asked what they
 * are transferring, so typing it is a moment of recognition rather than a
 * clipboard round trip.
 *
 * Nothing is weakened by the change. Both values are on the screen the
 * customer is looking at, so neither was ever a secret — the check is for
 * deliberateness, and it is the server that decides. Only the outer whitespace
 * a copy-paste leaves behind is forgiven; the comparison is otherwise exact,
 * in constant time, against the name the account actually has.
 */
final class TransferOwnershipRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', 'string', 'ulid'],
            'confirm_account_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_account_name.required' => __('validation.requests.team.confirm_account_name_required'),
        ];
    }

    public function memberId(): string
    {
        return (string) $this->input('member_id');
    }

    public function confirmation(): string
    {
        return trim((string) $this->input('confirm_account_name'));
    }
}
