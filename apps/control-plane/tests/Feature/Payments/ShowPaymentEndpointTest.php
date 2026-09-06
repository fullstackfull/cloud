<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/payments/{payment}.
 */
final class ShowPaymentEndpointTest extends PaymentsApiTestCase
{
    #[Test]
    public function a_payment_comes_back_with_its_amount_and_invoice(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $payment = Transaction::factory()
            ->forCustomer($customer)
            ->amount(Money::ofMinor(9000, 'KWD'))
            ->create(['invoice_id' => $invoice->id]);

        $this->actingAs($user)
            ->getJson("/api/v1/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $payment->id)
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.kind', 'charge')
            ->assertJsonPath('data.is_settled', true)
            ->assertJsonPath('data.invoice_id', $invoice->id)
            ->assertJsonPath('data.amount.minor_units', 9000)
            ->assertJsonPath('data.amount.currency', 'KWD')
            ->assertJsonPath('data.amount.amount', '9.000');
    }

    #[Test]
    public function another_customers_payment_is_not_found(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $foreign = Transaction::factory()->forCustomer($theirs)->create();

        // 404, not 403: on ULIDs the difference between "no such payment" and
        // "not your payment" is an enumeration oracle.
        $refused = $this->actingAs($mine)
            ->getJson("/api/v1/payments/{$foreign->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        $invented = $this->actingAs($mine)
            ->getJson('/api/v1/payments/01JZZZZZZZZZZZZZZZZZZZZZZZ')
            ->assertNotFound();

        $this->assertSame($invented->json('error.code'), $refused->json('error.code'));
        $this->assertSame($invented->json('error.message'), $refused->json('error.message'));

        $body = $refused->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString((string) $foreign->provider_reference, $body);
    }

    #[Test]
    public function nothing_internal_is_returned(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $payment = Transaction::factory()->forCustomer($customer)->create([
            'provider_metadata' => ['object' => ['raw' => 'provider payload']],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/payments/{$payment->id}")
            ->assertOk();

        $row = $response->json('data');

        $this->assertArrayNotHasKey('customer_id', $row);
        $this->assertArrayNotHasKey('provider_reference', $row);
        $this->assertArrayNotHasKey('provider_metadata', $row);

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('provider payload', $body);
        $this->assertStringNotContainsString($customer->id, $body);
    }

    #[Test]
    public function a_member_without_billing_permission_cannot_read_a_payment(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $member = $this->memberOf($customer, CustomerRole::Member);
        $payment = Transaction::factory()->forCustomer($customer)->create();

        $this->actingAs($member)
            ->getJson("/api/v1/payments/{$payment->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'auth.forbidden');

        $this->actingAs($owner)->getJson("/api/v1/payments/{$payment->id}")->assertOk();
    }
}
