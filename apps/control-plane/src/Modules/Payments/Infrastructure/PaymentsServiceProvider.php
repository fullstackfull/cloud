<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Payments\Domain\Contracts\PaymentProvider;
use Stripe\StripeClient;

/**
 * Container wiring for the payments module.
 *
 * The registry and manager are singletons because adapters memoise a
 * configured SDK client; the Stripe client itself is deferred behind a closure
 * so that a deployment with no Stripe credentials — every test run, and any
 * install using a different provider — never constructs it and never has to
 * supply a key it does not use.
 *
 * PaymentProvider resolves to the configured default, so ordinary callers
 * type-hint the interface and get the right driver. Webhook ingestion and
 * refunds deliberately do not use this binding: they resolve by the name
 * stored on the row, which may not be today's default.
 */
final class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentProviderRegistry::class);
        $this->app->singleton(PaymentProviderManager::class);

        $this->app->singleton(StripeClient::class, static function (): StripeClient {
            $config = ['api_key' => (string) config('services.stripe.secret')];

            // Pinning the API version keeps webhook payload shapes stable:
            // without it, Stripe rolling the account's default version can
            // silently change the fields the parser reads.
            $version = config('services.stripe.api_version');

            if (is_string($version) && $version !== '') {
                $config['stripe_version'] = $version;
            }

            return new StripeClient($config);
        });

        $this->app->bind(
            PaymentProvider::class,
            static fn ($app): PaymentProvider => $app->make(PaymentProviderManager::class)->default(),
        );
    }
}
