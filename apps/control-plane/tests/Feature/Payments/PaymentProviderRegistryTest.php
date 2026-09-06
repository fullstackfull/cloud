<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Lynomia\Modules\Payments\Domain\Exceptions\UnknownPaymentProviderException;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderManager;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Payments\Infrastructure\PaymentsServiceProvider;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Payments\Infrastructure\Providers\StripePaymentProvider;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Resolution is by persisted name, because that is what a webhook route and a
 * transaction row both carry.
 */
final class PaymentProviderRegistryTest extends TestCase
{
    private PaymentProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(PaymentsServiceProvider::class);
        $this->registry = $this->app->make(PaymentProviderRegistry::class);
    }

    #[Test]
    public function a_provider_is_resolved_by_the_name_it_is_persisted_under(): void
    {
        $this->assertInstanceOf(FakePaymentProvider::class, $this->registry->get('fake'));
        $this->assertSame('fake', $this->registry->get('fake')->name());
    }

    #[Test]
    public function stripe_resolves_with_its_sdk_client_wired_from_config(): void
    {
        config(['services.stripe.secret' => 'sk_test_0000000000000000000000']);

        $provider = $this->registry->get('stripe');

        $this->assertInstanceOf(StripePaymentProvider::class, $provider);
        $this->assertSame('stripe', $provider->name());
        $this->assertSame(
            'sk_test_0000000000000000000000',
            $this->app->make(StripeClient::class)->getApiKey(),
        );
    }

    #[Test]
    public function the_same_instance_is_returned_for_repeated_lookups(): void
    {
        // Adapters hold a configured HTTP client; rebuilding one per webhook
        // would rebuild the whole stack on every request.
        $this->assertSame($this->registry->get('fake'), $this->registry->get('fake'));
    }

    #[Test]
    public function the_name_is_matched_without_regard_to_case_or_stray_whitespace(): void
    {
        $this->assertSame('fake', $this->registry->get(' FAKE ')->name());
    }

    #[Test]
    public function an_unregistered_name_is_refused_with_the_names_that_do_exist(): void
    {
        try {
            $this->registry->get('paypal');
            $this->fail('An unknown provider was resolved.');
        } catch (UnknownPaymentProviderException $e) {
            $this->assertSame('payment.unknown_provider', $e->errorCode());
            $this->assertSame('paypal', $e->context()['requested']);
            $this->assertStringContainsString('fake', (string) $e->context()['known']);
        }
    }

    #[Test]
    public function the_manager_resolves_the_configured_default(): void
    {
        $manager = $this->app->make(PaymentProviderManager::class);

        $this->assertSame('fake', $manager->defaultName());
        $this->assertInstanceOf(FakePaymentProvider::class, $manager->default());
    }

    #[Test]
    public function the_manager_still_resolves_a_named_provider_that_is_not_the_default(): void
    {
        config(['services.stripe.secret' => 'sk_test_0000000000000000000000']);

        // Refunds on historical payments depend on this: switching the default
        // provider must not orphan them.
        $this->assertInstanceOf(StripePaymentProvider::class, $this->app
            ->make(PaymentProviderManager::class)
            ->driver('stripe'));
    }

    #[Test]
    public function a_driver_added_through_config_is_resolvable(): void
    {
        config(['payments.providers' => ['sandbox' => FakePaymentProvider::class]]);

        $this->assertTrue($this->registry->has('sandbox'));
        $this->assertContains('sandbox', $this->registry->names());
    }
}
