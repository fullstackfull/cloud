<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Activity\Application\Queries\CustomerActivity;
use Lynomia\Modules\Activity\Domain\Enums\ActivityCategory;

/**
 * Paging and filtering for an account's history.
 *
 * The cursor is validated only for shape, not for meaning: a mangled cursor is
 * treated by the query as "no cursor" and returns the newest page. The
 * alternative — a 422 on a cursor the client did not compose itself — turns a
 * copy-paste of a URL into an error screen, and a cursor is not a field a
 * customer fills in.
 *
 * `category` is an enum rule rather than a free string, because the filter is
 * applied by choosing which source branches to read. An unrecognised value
 * would silently mean "all", which is the one answer a filtered list must not
 * give.
 *
 * There is no `state` filter. It would have to be applied after the union —
 * every branch stores its own states, and mapping runs in PHP — so a page
 * filtered by state would return between zero and twenty-five rows out of
 * twenty-five and call itself a page. §9 forbids exactly that.
 */
final class ListActivityRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'string', Rule::enum(ActivityCategory::class)],
            'cursor' => ['sometimes', 'string', 'max:512'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.CustomerActivity::MAX_PER_PAGE],
        ];
    }

    public function category(): ?ActivityCategory
    {
        $value = $this->input('category');

        return is_string($value) && $value !== '' ? ActivityCategory::from($value) : null;
    }

    public function cursor(): ?string
    {
        $value = $this->input('cursor');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function perPage(): int
    {
        $value = $this->input('per_page');

        return is_numeric($value) ? (int) $value : CustomerActivity::DEFAULT_PER_PAGE;
    }
}
