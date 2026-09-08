<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bringing a name here from somewhere else.
 *
 * The authorisation code is the credential the losing registrar gave the
 * customer. It is validated for shape only — every registry spells it
 * differently, and a platform that guessed at the format would refuse valid
 * codes for namespaces nobody tested.
 *
 * It is held encrypted between payment and dispatch and erased on use. See the
 * migration that added the column.
 */
final class TransferDomainRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'string', 'ulid'],
            'authorisation_code' => ['required', 'string', 'min:6', 'max:128'],
        ];
    }
}
