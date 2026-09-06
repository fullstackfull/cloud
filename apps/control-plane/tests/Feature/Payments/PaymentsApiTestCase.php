<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Tests\Feature\Payments\Doubles\RecordingPaymentProvider;
use Tests\TestCase;

/**
 * Shared scaffolding for the payment endpoints.
 *
 * Two customers with two logins exist in almost every test here on purpose:
 * the interesting question about a payment API is not whether it can show you
 * yours, it is whether it can be talked into charging, or showing you,
 * somebody else's.
 */
abstract class PaymentsApiTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * A customer account with an accepted membership, and the user who holds
     * it.
     *
     * @return array{0: Customer, 1: User}
     */
    protected function accountWithOwner(CustomerRole $role = CustomerRole::Owner): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        return [$customer, $this->memberOf($customer, $role)];
    }

    protected function memberOf(Customer $customer, CustomerRole $role = CustomerRole::Owner, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            // An invitation that was never accepted grants nothing, so every
            // membership these tests rely on is explicitly accepted.
            'accepted_at' => now(),
        ]);

        return $user;
    }

    /**
     * An issued invoice awaiting payment, refreshed so that the generated
     * amount_due column is loaded rather than absent.
     */
    protected function openInvoice(Customer $customer, ?Money $total = null): Invoice
    {
        $total ??= Money::ofMinor(9000, 'KWD');

        /** @var Invoice $invoice */
        $invoice = Invoice::factory()
            ->for($customer)
            ->totalling($total)
            ->open()
            ->create();

        return $invoice->refresh();
    }

    /**
     * Puts a recording provider in front of the configured driver, so a test
     * can assert on what the platform actually sent.
     */
    protected function recordingProvider(): RecordingPaymentProvider
    {
        $recorder = new RecordingPaymentProvider;

        app(PaymentProviderRegistry::class)->swap(FakePaymentProvider::NAME, $recorder);

        return $recorder;
    }
}
