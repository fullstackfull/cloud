<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;

/**
 * What an operator must say to record a product.
 *
 * The kind is validated against the enum here as well as in the action. Two
 * checks rather than one because they answer different people: this one gives
 * a field-level validation error the Admin form can render beside the input,
 * and the action's gives the sentence that explains why the list is the
 * software rather than a setting. Neither is redundant — the action is
 * reachable from a test and a console, and a request rule is not a domain
 * invariant.
 */
final class RecordProductRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(ProductKind::class)],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],

            // Both languages, for the same reason an image's names are both
            // required: a catalogue entry in one language is one a customer in
            // the other reads in a language they did not choose.
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:160'],
            'name.ar' => ['required', 'string', 'max:160'],

            'description' => ['nullable', 'array'],
            'description.en' => ['nullable', 'string', 'max:2000'],
            'description.ar' => ['nullable', 'string', 'max:2000'],

            'is_active' => ['required', 'boolean'],
            'is_public' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
