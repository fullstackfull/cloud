<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Infrastructure;

use Illuminate\Contracts\Container\Container;
use Lynomia\Modules\Payments\Domain\Contracts\PaymentProvider;
use Lynomia\Modules\Payments\Domain\Exceptions\UnknownPaymentProviderException;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Payments\Infrastructure\Providers\StripePaymentProvider;

/**
 * Resolves a payment provider by the name it is persisted under.
 *
 * Lookup is by name rather than by class because the name is what lives in
 * transactions.provider and in webhook routes. A webhook arriving at
 * /webhooks/payments/stripe must resolve the same adapter that wrote the
 * transaction rows it refers to, years after the class may have moved.
 *
 * Instances are memoised: adapters hold a configured SDK client, and building
 * one per webhook would rebuild the HTTP stack on every request.
 */
final class PaymentProviderRegistry
{
    /**
     * The drivers shipped with the platform. Config may add to this map but
     * the built-ins are declared here so that a missing config file degrades
     * to "the fake and Stripe exist" rather than "no providers exist".
     *
     * @var array<string, class-string<PaymentProvider>>
     */
    private const array BUILT_IN = [
        FakePaymentProvider::NAME => FakePaymentProvider::class,
        StripePaymentProvider::NAME => StripePaymentProvider::class,
    ];

    /** @var array<string, PaymentProvider> */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @throws UnknownPaymentProviderException
     */
    public function get(string $name): PaymentProvider
    {
        $name = strtolower(trim($name));

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $drivers = $this->drivers();

        if (! isset($drivers[$name])) {
            throw UnknownPaymentProviderException::named($name, array_keys($drivers));
        }

        /** @var PaymentProvider $provider */
        $provider = $this->container->make($drivers[$name]);

        return $this->resolved[$name] = $provider;
    }

    public function has(string $name): bool
    {
        return isset($this->drivers()[strtolower(trim($name))]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->drivers());
    }

    /**
     * Replaces a driver for the lifetime of the container.
     *
     * Exists for tests that need a provider to misbehave — a refund that
     * throws, a retrieve that times out — which cannot be arranged through
     * config alone.
     */
    public function swap(string $name, PaymentProvider $provider): void
    {
        $this->resolved[strtolower(trim($name))] = $provider;
    }

    /**
     * @return array<string, class-string<PaymentProvider>>
     */
    private function drivers(): array
    {
        /** @var array<string, class-string<PaymentProvider>> $configured */
        $configured = config('payments.providers', []);

        return [...self::BUILT_IN, ...$configured];
    }
}
