<?php

declare(strict_types=1);

namespace Tests\Unit\Provisioning;

use InvalidArgumentException;
use Lynomia\Modules\Provisioning\Domain\Contracts\HandlerRegistry;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Exceptions\HandlerNotRegisteredException;
use Lynomia\Modules\Provisioning\Infrastructure\Handlers\FakeProvisioningHandler;
use Lynomia\Modules\Provisioning\Infrastructure\Providers\ProvisioningServiceProvider;
use Lynomia\Modules\Provisioning\Infrastructure\Registries\ProvisioningHandlerRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one seam through which provider-specific code enters a provider-agnostic
 * engine.
 */
final class HandlerRegistryTest extends TestCase
{
    private ProvisioningHandlerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ProvisioningHandlerRegistry($this->app);
    }

    #[Test]
    public function a_handler_is_resolved_by_the_kind_of_work_it_does(): void
    {
        $handler = new FakeProvisioningHandler(ProvisioningJobKind::Suspend);
        $this->registry->register($handler);

        // Lookup is by kind because the kind is what is persisted in the job
        // row, and a job queued today must still resolve after the handler
        // class has moved.
        $this->assertSame($handler, $this->registry->get(ProvisioningJobKind::Suspend));
        $this->assertTrue($this->registry->has(ProvisioningJobKind::Suspend));
        $this->assertSame(['suspend'], $this->registry->kinds());
    }

    #[Test]
    public function an_unregistered_kind_names_what_this_deployment_can_do(): void
    {
        $this->registry->register(new FakeProvisioningHandler(ProvisioningJobKind::CreateVps));

        try {
            $this->registry->get(ProvisioningJobKind::ProvisionDedicated);
            $this->fail('An unregistered kind must not resolve to anything.');
        } catch (HandlerNotRegisteredException $e) {
            $this->assertSame('provisioning.handler_not_registered', $e->errorCode());
            $this->assertSame('provision_dedicated', $e->context()['kind'] ?? null);
            $this->assertSame('create_vps', $e->context()['registered'] ?? null);
        }
    }

    #[Test]
    public function a_class_string_must_state_the_kind_it_handles(): void
    {
        // Asking the class itself would mean constructing it, which is exactly
        // what registering a class name is meant to avoid: eleven handlers
        // registered at boot must not build eleven HTTP clients.
        $this->expectException(InvalidArgumentException::class);

        $this->registry->register(FakeProvisioningHandler::class);
    }

    #[Test]
    public function a_handler_registered_under_the_wrong_kind_is_refused(): void
    {
        $this->registry->register(new FakeProvisioningHandler(ProvisioningJobKind::Stop), ProvisioningJobKind::DestroyVps);

        // Silently accepting this would mean a "stop" handler destroying a
        // customer's server.
        $this->expectException(InvalidArgumentException::class);

        $this->registry->get(ProvisioningJobKind::DestroyVps);
    }

    #[Test]
    public function the_service_provider_binds_one_shared_registry(): void
    {
        $this->app->register(ProvisioningServiceProvider::class);

        $viaInterface = $this->app->make(HandlerRegistry::class);
        $viaClass = $this->app->make(ProvisioningHandlerRegistry::class);

        // Handlers registered at boot have to still be there when a worker
        // resolves the registry by its interface.
        $this->assertSame($viaInterface, $viaClass);
    }

    #[Test]
    public function the_engine_ships_no_handlers_of_its_own(): void
    {
        $this->app->register(ProvisioningServiceProvider::class);

        /** @var HandlerRegistry $registry */
        $registry = $this->app->make(HandlerRegistry::class);

        // The module knows that work has kinds, not that Proxmox exists.
        // Everything provider-specific is registered by the application.
        $this->assertSame([], $registry->kinds());
    }
}
