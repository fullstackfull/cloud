<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Handing an account over takes the successor's membership id and the account's
 * own id typed back.
 *
 * The confirmation is the same device the irreversible cancellation and the
 * reinstall use: a value the caller can only supply by having read the screen.
 * Ownership transfer has no undo that does not depend on the goodwill of the
 * person who now owns the account, which puts it in the same class.
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
            'confirm_account_id' => ['required', 'string'],
        ];
    }

    public function memberId(): string
    {
        return (string) $this->input('member_id');
    }

    public function confirmation(): string
    {
        return (string) $this->input('confirm_account_id');
    }
}
