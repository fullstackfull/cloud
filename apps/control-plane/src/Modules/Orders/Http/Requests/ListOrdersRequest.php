<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;

/**
 * Filtering and paging for the order list.
 *
 * Note what is not here: no customer id, no account id. Which account's orders
 * are listed is decided by the acting-customer middleware, and a query string
 * that named one would be a request to read somebody else's history.
 */
final class ListOrdersRequest extends FormRequest
{
    /** Nobody gets more than this in one page, whatever they ask for. */
    public const MAX_PER_PAGE = 100;

    private const DEFAULT_PER_PAGE = 25;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Validated as an integer but not bounded here, because the bound
             * is applied by clamping rather than by refusing: a client asking
             * for 100000 rows wants "as many as I can have", and answering with
             * 100 is more useful than a 422. The clamp is the guarantee — the
             * page size the query sees can never exceed MAX_PER_PAGE
             * regardless of what arrives.
             */
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', new Enum(OrderStatus::class)],
        ];
    }

    public function perPage(): int
    {
        $requested = $this->integer('per_page', self::DEFAULT_PER_PAGE);

        if ($requested < 1) {
            $requested = self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }

    public function status(): ?OrderStatus
    {
        $status = $this->validated()['status'] ?? null;

        return is_string($status) && $status !== '' ? OrderStatus::from($status) : null;
    }
}
