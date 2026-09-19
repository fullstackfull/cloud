<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Http\Requests\ListInvoicesRequest;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/invoices and GET /api/v1/invoices/{invoice}.
 *
 * The surface is read-only, so most of what is asserted here is about what a
 * caller is *not* given: another tenant's documents, an unbounded page, the
 * account's frozen billing details, or the operator's note.
 */
final class InvoiceEndpointsTest extends BillingApiTestCase
{
    #[Test]
    public function a_customer_lists_their_own_invoices_newest_first(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $older = $this->invoiceFor($customer, ['issued_at' => now()->subDays(10)]);
        $newer = $this->invoiceFor($customer, ['issued_at' => now()->subDay()]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/invoices')
            ->assertOk();

        $response
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            // Counted rather than loaded: a list does not carry every line of
            // every document.
            ->assertJsonPath('data.0.items_count', 0)
            ->assertJsonMissingPath('data.0.items');
    }

    #[Test]
    public function the_list_shows_nothing_belonging_to_another_account(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $this->invoiceFor($theirs);

        $this->actingAs($mine)
            ->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function the_list_can_be_filtered_by_status(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $open = $this->invoiceFor($customer, ['status' => InvoiceStatus::Open, 'issued_at' => now()]);
        $this->invoiceFor($customer, ['status' => InvoiceStatus::Paid, 'issued_at' => now()]);

        $this->actingAs($user)
            ->getJson('/api/v1/invoices?status=open')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id);
    }

    #[Test]
    public function one_invoice_comes_back_with_its_lines_its_tax_and_what_is_owed(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // 9.000 net + 0.450 tax = 9.450 charged, of which 3.000 has been paid.
        $invoice = $this->invoiceFor($customer, [
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => 9000,
            'tax_minor' => 450,
            'total_minor' => 9450,
            'amount_paid_minor' => 3000,
            'issued_at' => now(),
            'due_at' => now()->addDays(7),
        ]);

        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'kind' => InvoiceItemKind::Plan,
            'description' => 'Cloud VPS — Small, monthly',
            'quantity' => 1,
            'unit_amount_minor' => 9000,
            'tax_minor' => 450,
            'total_minor' => 9450,
            'tax_rate' => '0.05',
            'tax_name' => 'VAT',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk();

        $response
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonPath('data.number', $invoice->number)
            ->assertJsonPath('data.status', InvoiceStatus::Open->value)
            ->assertJsonPath('data.is_payable', true)
            ->assertJsonPath('data.is_settled', false)

            // Money is an object, never a bare number: KWD has three minor
            // digits and a client that assumes two prints 94.50 for 9.450.
            ->assertJsonPath('data.total.minor_units', 9450)
            ->assertJsonPath('data.total.currency', 'KWD')
            ->assertJsonPath('data.total.amount', '9.450')
            ->assertJsonPath('data.tax.amount', '0.450')
            ->assertJsonPath('data.amount_paid.minor_units', 3000)

            // The generated column, read rather than recomputed:
            // 9450 − 3000 + 0.
            ->assertJsonPath('data.amount_due.minor_units', 6450)
            ->assertJsonPath('data.amount_due.amount', '6.450')

            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.description', 'Cloud VPS — Small, monthly')
            ->assertJsonPath('data.items.0.total.minor_units', 9450)
            ->assertJsonPath('data.items.0.tax.minor_units', 450)
            ->assertJsonPath('data.items.0.tax_name', 'VAT');

        // The rate stays a decimal string all the way out. A float would make
        // 0.05 something that is not 0.05.
        $this->assertIsString($response->json('data.items.0.tax_rate'));
        $this->assertSame('0.050000', $response->json('data.items.0.tax_rate'));

        // And no amount anywhere on the document is a bare number.
        $this->assertIsArray($response->json('data.total'));
        $this->assertIsArray($response->json('data.items.0.unit_amount'));
    }

    #[Test]
    public function a_refunded_invoice_is_owed_again_and_says_so(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // Paid in full and then refunded in full: the database derives
        // 9000 − 9000 + 9000, so the document is owed its total again.
        $invoice = $this->invoiceFor($customer, [
            'status' => InvoiceStatus::Refunded,
            'amount_paid_minor' => 9000,
            'amount_refunded_minor' => 9000,
            'issued_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.amount_refunded.minor_units', 9000)
            ->assertJsonPath('data.amount_due.minor_units', 9000);
    }

    #[Test]
    public function another_accounts_invoice_is_not_found_rather_than_forbidden(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $notMine = $this->invoiceFor($theirs);

        // 404, not 403. A 403 would confirm the id exists, which on ULIDs is
        // an enumeration oracle over every invoice on the platform.
        $this->actingAs($mine)
            ->getJson("/api/v1/invoices/{$notMine->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');
    }

    #[Test]
    public function another_accounts_invoice_is_indistinguishable_from_one_that_does_not_exist(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $real = $this->actingAs($mine)
            ->getJson('/api/v1/invoices/'.$this->invoiceFor($theirs)->id);

        $invented = $this->actingAs($mine)
            ->getJson('/api/v1/invoices/01JZZZZZZZZZZZZZZZZZZZZZZZ');

        // Byte for byte the same answer, or the difference sorts real invoice
        // ids from invented ones.
        $this->assertSame($real->status(), $invented->status());
        $this->assertSame($real->json('error.code'), $invented->json('error.code'));
        $this->assertSame($real->json('error.message'), $invented->json('error.message'));
    }

    #[Test]
    public function an_account_the_caller_also_owns_is_still_out_of_scope_for_this_request(): void
    {
        [$acting, $other, $user] = $this->twoAccountsOneLogin();

        $mine = $this->invoiceFor($acting, ['issued_at' => now()]);
        $theirs = $this->invoiceFor($other, ['issued_at' => now()]);

        // The header decided which account this request acts for. Membership of
        // the second one is not a licence to read it through the first: two
        // companies can share a bookkeeper, and each is entitled to be billed
        // in its own right.
        $list = $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $acting->id)
            ->getJson('/api/v1/invoices')
            ->assertOk();

        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->assertStringNotContainsString($theirs->id, (string) $list->getContent());

        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $acting->id)
            ->getJson("/api/v1/invoices/{$theirs->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');
    }

    #[Test]
    public function a_member_of_the_account_without_billing_permission_may_not_read_invoices(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        // A colleague added to watch the servers, not to see the money.
        $member = $this->memberOf($customer, CustomerRole::Member);
        $invoice = $this->invoiceFor($customer);

        $this->actingAs($member)
            ->getJson('/api/v1/invoices')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'auth.forbidden');

        // The refusal is decided by the role and never by the lookup, so it is
        // the same 403 for an id that really is on their own account.
        $this->actingAs($member)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'auth.forbidden');
    }

    #[Test]
    public function a_page_size_larger_than_the_ceiling_is_clamped_not_obeyed(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        Invoice::factory()->count(3)->create(['customer_id' => $customer->id]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/invoices?per_page=100000')
            ->assertOk();

        // The caller asked for a hundred thousand rows and is told the ceiling
        // rather than refused — but the query can never have seen the number
        // they sent.
        $response
            ->assertJsonPath('meta.per_page', ListInvoicesRequest::MAX_PER_PAGE)
            ->assertJsonPath('meta.max_per_page', ListInvoicesRequest::MAX_PER_PAGE);
    }

    #[Test]
    public function paging_walks_the_collection_without_repeating_a_row(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        Invoice::factory()->count(5)->create([
            'customer_id' => $customer->id,
            // Issued in the same millisecond, so only the ULID tie-break keeps
            // the order stable across the two requests.
            'issued_at' => now(),
        ]);

        $first = $this->actingAs($user)->getJson('/api/v1/invoices?per_page=2')->assertOk();
        $second = $this->actingAs($user)->getJson('/api/v1/invoices?per_page=2&page=2')->assertOk();

        $first->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3);

        $ids = array_merge(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        );

        $this->assertCount(4, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    #[Test]
    public function a_status_filter_that_is_not_a_status_is_a_422_naming_the_field(): void
    {
        [, $user] = $this->accountWithOwner();

        $this->actingAs($user)
            ->getJson('/api/v1/invoices?status=nearly_paid')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);
    }

    #[Test]
    public function a_non_numeric_page_size_is_a_422_naming_the_field(): void
    {
        [, $user] = $this->accountWithOwner();

        $this->actingAs($user)
            ->getJson('/api/v1/invoices?per_page=all')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['per_page']]]]);
    }

    #[Test]
    public function nothing_internal_to_the_platform_appears_on_an_invoice(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $invoice = $this->invoiceFor($customer, [
            'issued_at' => now(),
            'notes' => 'Manually adjusted by finance after chargeback LYN-99.',
            'billing_snapshot' => [
                'display_name' => 'Premier Care',
                'tax_id' => 'KW-TAX-4471',
                'address' => ['line1' => 'Block 4, Salmiya', 'country' => 'KW'],
            ],
        ]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

        $show = $this->actingAs($user)->getJson("/api/v1/invoices/{$invoice->id}")->assertOk();
        $index = $this->actingAs($user)->getJson('/api/v1/invoices')->assertOk();

        foreach ([$show->json('data'), $index->json('data.0')] as $document) {
            // The account the caller is already acting for.
            $this->assertArrayNotHasKey('customer_id', $document);
            // An operator's note, not the customer's.
            $this->assertArrayNotHasKey('notes', $document);
            // The generated column is exposed as money, never as a raw column.
            $this->assertArrayNotHasKey('amount_due_minor', $document);
            $this->assertArrayNotHasKey('total_minor', $document);
        }

        /*
         * The billing snapshot is the customer's own billing details as they
         * stood when the invoice was issued — the name and address that have
         * to appear on the document, and the tax id they gave us. It belongs
         * on the invoice they are reading and is deliberately absent from a
         * list of twelve of them, which has no use for twelve addresses.
         *
         * What must not be in it is the platform's internal handle for the
         * account.
         */
        $this->assertArrayHasKey('billing_snapshot', $show->json('data'));
        $this->assertArrayNotHasKey('billing_snapshot', $index->json('data.0'));
        $this->assertSame('Premier Care', $show->json('data.billing_snapshot.display_name'));
        $this->assertSame('KW-TAX-4471', $show->json('data.billing_snapshot.tax_id'));
        $this->assertArrayNotHasKey('customer_id', (array) $show->json('data.billing_snapshot'));

        // The operator's note and the account's internal id are nowhere in the
        // body, including inside a line.
        $body = $show->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('chargeback LYN-99', $body);
        $this->assertStringNotContainsString($customer->id, $body);
    }
}
