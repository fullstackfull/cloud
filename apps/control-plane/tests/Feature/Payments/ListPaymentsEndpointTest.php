<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/payments.
 */
final class ListPaymentsEndpointTest extends PaymentsApiTestCase
{
    #[Test]
    public function an_account_sees_its_own_payments(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        Transaction::factory()->count(3)->forCustomer($customer)->create();

        $this->actingAs($user)
            ->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.max_per_page', 100);
    }

    #[Test]
    public function money_is_returned_as_minor_units_with_its_currency(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        Transaction::factory()
            ->forCustomer($customer)
            ->amount(Money::ofMinor(12500, 'KWD'))
            ->create();

        $this->actingAs($user)
            ->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonPath('data.0.amount.minor_units', 12500)
            ->assertJsonPath('data.0.amount.currency', 'KWD')
            ->assertJsonPath('data.0.amount.amount', '12.500');
    }

    #[Test]
    public function another_accounts_payments_are_not_listed(): void
    {
        [$mine, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $ours = Transaction::factory()->forCustomer($mine)->create();
        $foreign = Transaction::factory()->forCustomer($theirs)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/payments')->assertOk();

        $this->assertSame([$ours->id], array_column($response->json('data'), 'id'));
        $this->assertJsonMissesTheirRow($response->getContent(), $foreign->id);
    }

    #[Test]
    public function the_page_size_is_bounded_however_much_is_asked_for(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        Transaction::factory()->count(30)->forCustomer($customer)->create();

        // Clamped rather than refused: "as many as I can have" is a reasonable
        // thing to mean, and 100 is a more useful answer than a 422.
        $this->actingAs($user)
            ->getJson('/api/v1/payments?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonCount(30, 'data');

        $this->actingAs($user)
            ->getJson('/api/v1/payments?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 15)
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function the_history_can_be_filtered_by_status(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        Transaction::factory()->count(2)->forCustomer($customer)->create();
        $failed = Transaction::factory()->forCustomer($customer)->failed()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/payments?status='.TransactionStatus::Failed->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $failed->id);
    }

    #[Test]
    public function an_unknown_status_is_a_validation_failure(): void
    {
        [, $user] = $this->accountWithOwner();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/payments?status=definitely-not-a-status')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed');

        $this->assertArrayHasKey('status', $response->json('error.details.fields'));
    }

    #[Test]
    public function nothing_internal_is_listed(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $transaction = Transaction::factory()->forCustomer($customer)->create([
            'provider_metadata' => ['object' => ['secret_looking' => 'value']],
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/payments')->assertOk();

        $row = $response->json('data.0');

        $this->assertArrayNotHasKey('customer_id', $row);
        $this->assertArrayNotHasKey('provider_reference', $row);
        $this->assertArrayNotHasKey('provider_metadata', $row);
        $this->assertArrayNotHasKey('internal_notes', $row);
        $this->assertArrayNotHasKey('last_error', $row);

        // Named specifically rather than by shape: the reference and the raw
        // provider object are the two things that must not travel.
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString((string) $transaction->provider_reference, $body);
        $this->assertStringNotContainsString('secret_looking', $body);
    }

    #[Test]
    public function a_member_without_billing_permission_cannot_read_the_history(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $member = $this->memberOf($customer, CustomerRole::Member);
        Transaction::factory()->forCustomer($customer)->create();

        $this->actingAs($member)
            ->getJson('/api/v1/payments')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'auth.forbidden');

        $this->actingAs($owner)->getJson('/api/v1/payments')->assertOk();
    }

    private function assertJsonMissesTheirRow(string|false $body, string $foreignId): void
    {
        $this->assertIsString($body);
        $this->assertStringNotContainsString($foreignId, $body);
    }
}
