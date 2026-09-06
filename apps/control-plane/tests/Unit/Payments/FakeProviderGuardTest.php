<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use Lynomia\Modules\Payments\Domain\Exceptions\FakeProviderInProductionException;
use Lynomia\Modules\Payments\Domain\Services\FakeProviderGuard;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The guard exists to prevent the failure where production reports captures
 * that never happened, so the test asserts the refusal is total: the object
 * cannot be constructed, and therefore cannot be resolved either.
 */
final class FakeProviderGuardTest extends TestCase
{
    #[Test]
    public function the_fake_provider_refuses_to_be_constructed_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(FakeProviderInProductionException::class);

        new FakePaymentProvider;
    }

    #[Test]
    public function the_refusal_carries_a_stable_error_code_and_a_server_error_status(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            new FakePaymentProvider;
            $this->fail('The fake provider was constructed in production.');
        } catch (FakeProviderInProductionException $e) {
            $this->assertSame('payment.fake_provider_in_production', $e->errorCode());
            $this->assertSame(500, $e->httpStatus());
            $this->assertSame('fake', $e->context()['provider']);
        }
    }

    #[Test]
    public function resolving_the_fake_through_the_registry_is_refused_in_production_too(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(FakeProviderInProductionException::class);

        (new PaymentProviderRegistry($this->app))->get('fake');
    }

    #[Test]
    public function the_guard_permits_every_other_environment(): void
    {
        foreach (['local', 'testing', 'staging'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);

            FakeProviderGuard::assertNotProduction('fake');
        }

        $this->addToAssertionCount(3);
    }
}
