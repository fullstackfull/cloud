<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Http\Requests\Concerns\BoundsPageSize;

/**
 * Filtering and paging for the payment history.
 *
 * Note what is not here: no customer id, no account id, no invoice owner.
 * Whose payments are listed is decided by the acting-customer middleware, and
 * a query string that named an account would be a request to read somebody
 * else's ledger.
 */
final class ListPaymentsRequest extends FormRequest
{
    use BoundsPageSize;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Validated as an integer but not bounded here, because the bound
             * is applied by clamping rather than by refusing — see
             * BoundsPageSize. The clamp is the guarantee; this rule only keeps
             * "per_page=banana" from reaching it.
             */
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', new Enum(TransactionStatus::class)],
            'kind' => ['sometimes', 'nullable', new Enum(TransactionKind::class)],
        ];
    }

    public function status(): ?TransactionStatus
    {
        $status = $this->validated()['status'] ?? null;

        return is_string($status) && $status !== '' ? TransactionStatus::from($status) : null;
    }

    public function kind(): ?TransactionKind
    {
        $kind = $this->validated()['kind'] ?? null;

        return is_string($kind) && $kind !== '' ? TransactionKind::from($kind) : null;
    }
}
