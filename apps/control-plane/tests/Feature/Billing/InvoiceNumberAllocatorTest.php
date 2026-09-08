<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Infrastructure\Services\InvoiceNumberAllocator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class InvoiceNumberAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceNumberAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = app(InvoiceNumberAllocator::class);
        config()->set('billing.invoice_number.prefix', 'LYN');
        config()->set('billing.invoice_number.padding', 6);

        /*
         * Reset the sequence explicitly. RefreshDatabase rolls each test back
         * in a transaction, but a sequence is deliberately not transactional —
         * which is the very property the rollback test below asserts — so it
         * would otherwise carry values across tests.
         */
        DB::statement("SELECT setval('invoice_number_seq', 1, false)");
    }

    #[Test]
    public function numbers_are_sequential_and_formatted_from_configuration(): void
    {
        $this->assertSame('LYN-000001', $this->allocator->next());
        $this->assertSame('LYN-000002', $this->allocator->next());
        $this->assertSame('LYN-000003', $this->allocator->next());
    }

    #[Test]
    public function the_format_follows_configuration(): void
    {
        config()->set('billing.invoice_number.prefix', 'INV');
        config()->set('billing.invoice_number.padding', 4);

        $this->assertSame('INV-0001', $this->allocator->next());
    }

    #[Test]
    public function concurrent_allocations_on_separate_connections_never_collide(): void
    {
        /*
         * The failure this guards against is specific: with MAX(number) + 1,
         * two renewal workers running at the same instant read the same maximum
         * and build two invoices with the same number. One then violates a
         * unique constraint after its line items already exist.
         *
         * Separate connections are used deliberately — two allocations on one
         * connection are serialised by definition and would prove nothing.
         */
        foreach (['concurrent_a', 'concurrent_b'] as $name) {
            config()->set("database.connections.{$name}", config('database.connections.pgsql'));
        }

        $numbers = [];
        foreach (['pgsql', 'concurrent_a', 'concurrent_b'] as $connection) {
            $row = DB::connection($connection)->selectOne('SELECT nextval(?) AS nextval', ['invoice_number_seq']);
            $numbers[] = (int) $row->nextval;
        }

        $this->assertSame($numbers, array_values(array_unique($numbers)), 'A sequence must never hand out a value twice.');
        $this->assertSame([1, 2, 3], $numbers);
    }

    #[Test]
    public function a_rolled_back_transaction_burns_its_number_rather_than_recycling_it(): void
    {
        /*
         * Sequences are deliberately not transactional. A gap is an accounting
         * question an operator can answer from the issued-invoice report; a
         * duplicate invoice number is a legal problem. Pinning the behaviour
         * here makes it a decision rather than an accident.
         */
        $first = $this->allocator->next();

        try {
            DB::transaction(function (): void {
                $this->allocator->next();
                throw new \RuntimeException('simulated failure after allocation');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame('LYN-000001', $first);
        $this->assertSame('LYN-000003', $this->allocator->next());
    }

    #[Test]
    public function numbers_widen_rather_than_truncate_past_the_padding_boundary(): void
    {
        config()->set('billing.invoice_number.padding', 3);
        DB::statement("SELECT setval('invoice_number_seq', 999, true)");

        $this->assertSame('LYN-1000', $this->allocator->next());
    }
}
