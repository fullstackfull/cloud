<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Infrastructure;

use Lynomia\Modules\Payments\Domain\Contracts\PaymentProvider;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;

/**
 * The configured default provider.
 *
 * Separate from the registry because the two answer different questions.
 * Checkout asks "which provider should take this payment?" and gets the
 * default; webhook ingestion and refunds ask "which provider is this
 * transaction's?" and must get the one named on the row, even after the
 * default has changed. Collapsing them would mean that switching providers
 * quietly broke refunds on every historical payment.
 */
final class PaymentProviderManager
{
    public function __construct(
        private readonly PaymentProviderRegistry $registry,
    ) {}

    /**
     * The configured driver name. Reuses billing.providers.payment, which the
     * production boot check already inspects, so there is one answer to "which
     * provider is this deployment using?" rather than two that can disagree.
     */
    public function defaultName(): string
    {
        $name = config('billing.providers.payment');

        return is_string($name) && $name !== '' ? strtolower($name) : FakePaymentProvider::NAME;
    }

    public function default(): PaymentProvider
    {
        return $this->registry->get($this->defaultName());
    }

    public function driver(?string $name = null): PaymentProvider
    {
        return $name === null ? $this->default() : $this->registry->get($name);
    }

    public function registry(): PaymentProviderRegistry
    {
        return $this->registry;
    }
}
