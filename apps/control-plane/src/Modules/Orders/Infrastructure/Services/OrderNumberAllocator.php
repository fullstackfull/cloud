<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Infrastructure\Services;

use Illuminate\Support\Facades\DB;

/**
 * Allocates the next order number, from its own sequence.
 *
 * Kept separate from invoice numbering because the two series have different
 * regulatory weight: an order number is a convenience for support, while an
 * invoice number is a document identifier that a tax authority may audit.
 */
final class OrderNumberAllocator
{
    private const string SEQUENCE = 'order_number_seq';

    public function next(): string
    {
        /** @var object{nextval: int|string} $row */
        $row = DB::selectOne('SELECT nextval(?) AS nextval', [self::SEQUENCE]);

        $prefix = (string) config('billing.order_number.prefix', 'ORD');
        $padding = (int) config('billing.order_number.padding', 6);

        return sprintf('%s-%s', $prefix, str_pad((string) $row->nextval, $padding, '0', STR_PAD_LEFT));
    }
}
