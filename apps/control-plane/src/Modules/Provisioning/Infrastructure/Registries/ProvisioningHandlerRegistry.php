<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Infrastructure\Registries;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Lynomia\Modules\Provisioning\Domain\Contracts\HandlerRegistry;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Exceptions\HandlerNotRegisteredException;

/**
 * The one seam through which provider-specific code enters the engine.
 *
 * Handlers may be registered as instances or as class strings. Class strings
 * are the normal case at boot: constructing a handler builds its SDK client
 * and opens its configuration, and an application that registers eleven
 * handlers must not pay for eleven HTTP stacks in order to run one job.
 *
 * Resolved instances are memoised, so a worker draining a queue of a hundred
 * jobs builds each client once.
 */
final class ProvisioningHandlerRegistry implements HandlerRegistry
{
    /** @var array<string, ProvisioningHandler|class-string<ProvisioningHandler>> */
    private array $handlers = [];

    /** @var array<string, ProvisioningHandler> */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(ProvisioningHandler|string $handler, ?ProvisioningJobKind $kind = null): void
    {
        if ($kind === null) {
            if (! $handler instanceof ProvisioningHandler) {
                // Asking a class string what kind it handles means building it,
                // which is exactly what registering a class string is meant to
                // avoid. The kind is cheap for the caller to state.
                throw new InvalidArgumentException(
                    'A provisioning handler registered by class name must declare the kind it handles.'
                );
            }

            $kind = $handler->kind();
        }

        $this->handlers[$kind->value] = $handler;
        unset($this->resolved[$kind->value]);
    }

    public function get(ProvisioningJobKind $kind): ProvisioningHandler
    {
        if (isset($this->resolved[$kind->value])) {
            return $this->resolved[$kind->value];
        }

        if (! isset($this->handlers[$kind->value])) {
            throw HandlerNotRegisteredException::forKind($kind, $this->kinds());
        }

        $handler = $this->handlers[$kind->value];

        if (is_string($handler)) {
            /** @var ProvisioningHandler $handler */
            $handler = $this->container->make($handler);
        }

        if ($handler->kind() !== $kind) {
            // A handler registered under the wrong key would silently perform
            // the wrong operation on a customer's server.
            throw new InvalidArgumentException(sprintf(
                'The handler registered for "%s" reports that it handles "%s".',
                $kind->value,
                $handler->kind()->value,
            ));
        }

        return $this->resolved[$kind->value] = $handler;
    }

    public function has(ProvisioningJobKind $kind): bool
    {
        return isset($this->handlers[$kind->value]);
    }

    /**
     * @return list<string>
     */
    public function kinds(): array
    {
        return array_keys($this->handlers);
    }
}
