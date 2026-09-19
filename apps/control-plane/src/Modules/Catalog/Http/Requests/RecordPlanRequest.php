<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Catalog\Application\Actions\RecordPlan;

/**
 * What an operator must say to record a plan.
 *
 * `resources` is validated as a document with integer-or-string leaves rather
 * than against a fixed set of columns, because the column is jsonb by design:
 * a VPS plan, a hosting plan and a dedicated plan describe different things.
 * What each *kind* must contain is a domain question, and it is answered in
 * {@see RecordPlan} where the
 * product — and therefore the kind — is known.
 */
final class RecordPlanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'string', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],

            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:160'],
            'name.ar' => ['required', 'string', 'max:160'],

            'description' => ['nullable', 'array'],
            'description.en' => ['nullable', 'string', 'max:2000'],
            'description.ar' => ['nullable', 'string', 'max:2000'],

            'resources' => ['required', 'array'],
            'placement_constraints' => ['nullable', 'array'],

            'stock_limit' => ['nullable', 'integer', 'min:0'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],

            'is_active' => ['required', 'boolean'],
            'is_public' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
