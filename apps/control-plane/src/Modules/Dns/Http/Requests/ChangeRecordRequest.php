<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An edit changes what a record says, never what it is.
 *
 * Neither the name nor the type is accepted here, and that is a product
 * decision rather than an oversight: a record with a different name is a
 * different record, and letting one be edited into another would leave the
 * provider holding the old value under an identifier the platform has quietly
 * reassigned. A customer who wants a different name deletes and adds, and sees
 * both steps.
 */
final class ChangeRecordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required_without:data', 'nullable', 'string', 'max:2048'],
            'ttl' => ['sometimes', 'integer'],
            'priority' => ['sometimes', 'nullable', 'integer', 'between:0,65535'],
            'data' => ['sometimes', 'array'],
            'data.flags' => ['required_with:data', 'integer', 'between:0,255'],
            'data.tag' => ['required_with:data', 'string', 'max:16'],
            'data.value' => ['required_with:data', 'string', 'max:255'],
        ];
    }
}
