<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Lynomia\Http\Concerns\BoundsPageSize;

/**
 * Paging for one service's provisioning history.
 *
 * There is no filter on this list, and that is a decision rather than an
 * omission. The history is short, it is read to answer "what happened to my
 * server", and every filter worth having here — by failure class, by provider,
 * by attempt count — is a filter over the operational detail this surface
 * exists not to publish.
 *
 * The service is named in the path and resolved through the acting customer;
 * nothing in the query string selects which service is read.
 */
final class ListServiceEventsRequest extends FormRequest
{
    use BoundsPageSize;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
