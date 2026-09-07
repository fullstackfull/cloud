<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use Lynomia\Modules\Subscriptions\Application\Actions\AdvanceDunning;
use Lynomia\Modules\Subscriptions\Application\Actions\SweepSubscriptionLifecycle;
use Lynomia\Modules\Subscriptions\Application\Actions\TransitionSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sequence a real customer takes when their card expires.
 *
 * Every step of it existed and the steps were not joined up. Dunning could
 * move a subscription to suspended and nothing switched the service off;
 * AdvanceDunning::recordSuccessfulPayment could unwind all of it and nothing
 * called it. So a customer who missed a payment kept full use of their server
 * until the day it was terminated, and a customer who then paid in full was
 * terminated anyway — the money arrived, the invoice was marked paid, and the
 * subscription sat suspended waiting for a clock that only counted down.
 *
 * This is deliberately one test through the whole arc rather than four unit
 * tests of the parts. The parts all passed before.
 */
final class PaymentAfterSuspensionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_customer_who_pays_after_suspension_gets_their_service_back(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));

        [$subscription, $service] = $this->activeSubscriptionWithService();

        // ------------------------------------------------------------------
        // The payment fails and the grace clock starts.
        // ------------------------------------------------------------------
        app(AdvanceDunning::class)
            ->recordFailedPayment($subscription);

        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);
        $this->assertSame(
            ServiceStatus::Active,
            $service->refresh()->status,
            'Going past due must not switch anything off; the grace period is the whole point.',
        );

        // ------------------------------------------------------------------
        // Grace expires. The sweep suspends — and the service follows.
        // ------------------------------------------------------------------
        $this->travelTo(CarbonImmutable::parse('2026-03-20 09:00:00'));

        app(SweepSubscriptionLifecycle::class)->execute();

        $this->assertSame(SubscriptionStatus::Suspended, $subscription->refresh()->status);
        $this->assertSame(
            ServiceStatus::Suspended,
            $service->refresh()->status,
            'The subscription was suspended and the customer kept full use of the service.',
        );

        // ------------------------------------------------------------------
        // The customer pays the renewal invoice.
        // ------------------------------------------------------------------
        $invoice = $this->openRenewalInvoiceFor($subscription);

        app(SettleInvoice::class)->execute($invoice, $this->captureFor($invoice));

        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame(
            ServiceStatus::Active,
            $service->refresh()->status,
            'The customer paid in full and their service was never restored.',
        );

        // The dunning clocks are wiped, not merely stepped over: leaving an
        // expired deadline behind would suspend them again on the next sweep.
        $this->assertNull($subscription->grace_period_ends_at);
        $this->assertNull($subscription->suspended_at);
        $this->assertSame(0, $subscription->failed_payment_count);

        // ------------------------------------------------------------------
        // And the termination clock is genuinely off, not just reset.
        // ------------------------------------------------------------------
        $this->travelTo(CarbonImmutable::parse('2026-04-30 09:00:00'));

        app(SweepSubscriptionLifecycle::class)->execute();

        $this->assertSame(
            SubscriptionStatus::Active,
            $subscription->refresh()->status,
            'A paid-up subscription was terminated by a clock that should have been stopped.',
        );
    }

    #[Test]
    public function suspending_a_hosting_subscription_reaches_the_control_panel(): void
    {
        /*
         * A shared hosting account serves web traffic the platform does not
         * sit in front of. Marking the service row suspended locks the
         * customer out of the portal and leaves their site up, so the panel
         * has to be told — and told again, in the other direction, when they
         * pay. Both of those actions were written and neither had a caller.
         */
        $this->app->singleton(HostingProviderFactory::class);

        $node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        /** @var FakeHostingProvider $panel */
        $panel = app(HostingProviderFactory::class)->for($node);

        [$subscription, $service] = $this->activeSubscriptionWithService(ProductKind::SharedHosting);

        $panel->createAccount($node, new CreateAccountRequest(
            username: 'lifecycle01',
            primaryDomain: 'lifecycle01.example.test',
            password: 's3cret-panel-password',
            packageName: 'starter',
            contactEmail: 'owner@lifecycle01.example.test',
        ));

        HostingAccount::factory()->named('lifecycle01')->create([
            'hosting_node_id' => $node->getKey(),
            'customer_id' => $subscription->customer_id,
            'service_id' => $service->getKey(),
        ]);

        app(TransitionSubscription::class)->execute($subscription, SubscriptionStatus::PastDue);
        app(TransitionSubscription::class)->execute($subscription, SubscriptionStatus::Suspended);

        $this->assertSame(
            HostingAccountStatus::Suspended,
            HostingAccount::query()->where('service_id', $service->getKey())->sole()->status,
        );
        $this->assertTrue(
            $this->remoteIsSuspended($panel, $node, 'lifecycle01'),
            'The platform recorded a suspension the control panel was never told about.',
        );

        app(TransitionSubscription::class)->execute($subscription, SubscriptionStatus::Active);

        $this->assertSame(
            HostingAccountStatus::Active,
            HostingAccount::query()->where('service_id', $service->getKey())->sole()->status,
        );
        $this->assertFalse(
            $this->remoteIsSuspended($panel, $node, 'lifecycle01'),
            'The customer paid and their site stayed suspended on the panel.',
        );
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
    }

    private function remoteIsSuspended(FakeHostingProvider $panel, HostingNode $node, string $username): bool
    {
        foreach ($panel->listAccounts($node) as $account) {
            if ($account->username === $username) {
                return $account->suspended;
            }
        }

        $this->fail(sprintf('The panel has no account called %s at all.', $username));
    }

    /**
     * @return array{Subscription, Service}
     */
    private function activeSubscriptionWithService(ProductKind $kind = ProductKind::Vps): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-02-01 00:00:00'), BillingPeriod::Monthly)
            ->priced(9_000)
            ->create(['customer_id' => $customer->id, 'status' => SubscriptionStatus::Active]);

        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->getKey(),
            'kind' => $kind->value,
            'status' => ServiceStatus::Active,
        ]);

        return [$subscription, $service];
    }

    private function openRenewalInvoiceFor(Subscription $subscription): Invoice
    {
        return Invoice::factory()
            ->open()
            ->totalling(Money::ofMinor(9_000, 'KWD'))
            ->create([
                'customer_id' => $subscription->customer_id,
                'subscription_id' => $subscription->getKey(),
                'order_id' => null,
                'currency' => 'KWD',
            ]);
    }

    private function captureFor(Invoice $invoice): Transaction
    {
        return Transaction::factory()
            ->amount(Money::ofMinor($invoice->total_minor, $invoice->currency))
            ->create([
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->getKey(),
            ]);
    }
}
