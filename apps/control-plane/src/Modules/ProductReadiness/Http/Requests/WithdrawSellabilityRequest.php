<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WithdrawSellabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
