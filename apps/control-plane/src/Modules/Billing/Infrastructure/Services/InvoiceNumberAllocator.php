<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Infrastructure\Services;

use Illuminate\Support\Facades\DB;

/**
 * Allocates the next invoice number.
 *
 * Backed by a PostgreSQL sequence rather than MAX(number) + 1.
 *
 * The read-modify-write approach is not merely slower: under two concurrent
 * renewal workers it hands the same number to both, and one of the two invoices
 * then fails a unique constraint after its line items have been built. A
 * sequence is atomic, needs no application lock, and never blocks a second
 * caller.
 *
 * Sequences do skip values when a transaction rolls back. That is the correct
 * trade for this platform: a gap in numbering is an accounting question an
 * operator can answer, whereas a duplicate number is a legal problem. Where a
 * jurisdiction requires strictly gapless numbering, that is a reconciliation
 * report over issued invoices, not a change to allocation.
 */
final class InvoiceNumberAllocator
{
    private const string SEQUENCE = 'invoice_number_seq';

    public function next(): string
    {
        /** @var object{nextval: int|string} $row */
        $row = DB::selectOne('SELECT nextval(?) AS nextval', [self::SEQUENCE]);

        return $this->format((int) $row->nextval);
    }

    private function format(int $value): string
    {
        $prefix = (string) config('billing.invoice_number.prefix', 'LYN');
        $padding = (int) config('billing.invoice_number.padding', 6);

        return sprintf('%s-%s', $prefix, str_pad((string) $value, $padding, '0', STR_PAD_LEFT));
    }
}
