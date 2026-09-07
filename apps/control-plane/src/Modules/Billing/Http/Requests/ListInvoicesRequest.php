<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Http\Requests\Concerns\BoundsPageSize;

/**
 * Filtering and paging for the invoice list.
 *
 * Note what is not here: no customer id, no account id, no subscription id.
 * Which account's invoices are listed is decided by the acting-customer
 * middleware, and a query string that named one would be a request to read
 * somebody else's billing history.
 */
final class ListInvoicesRequest extends FormRequest
{
    use BoundsPageSize;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Validated as an integer but not bounded here; see BoundsPageSize.
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', new Enum(InvoiceStatus::class)],
        ];
    }

    public function status(): ?InvoiceStatus
    {
        $status = $this->validated()['status'] ?? null;

        return is_string($status) && $status !== '' ? InvoiceStatus::from($status) : null;
    }
}
