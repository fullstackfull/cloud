<?php

declare(strict_types=1);

namespace Lynomia\Modules\ApiKeys\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Revocation takes a reason and nothing else.
 *
 * No password confirmation here, on purpose. Minting a credential is the act
 * that must be hard; turning one off is what somebody does at speed when a key
 * has just been posted to a public repository, and a password prompt in that
 * path buys an attacker minutes.
 */
final class RevokeApiTokenRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Kept short because the column is: PersonalAccessToken::revoke()
            // truncates at 128, and a rule that accepted more would promise a
            // record that is silently cut.
            'reason' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }

    /**
     * The reason to record, falling back to who did it.
     *
     * Never empty: "revoked, cause unknown" is the answer nobody can use six
     * months later, and the acting user's id is at least a thread to pull.
     */
    public function reason(User $user): string
    {
        $reason = $this->validated()['reason'] ?? null;

        return is_string($reason) && trim($reason) !== ''
            ? trim($reason)
            : 'Revoked by user '.$user->getKey();
    }
}
