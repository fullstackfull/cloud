<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Contracts;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Exceptions\HandlerNotRegisteredException;

/**
 * Where the engine finds the handler for a kind of work.
 *
 * Lookup is by kind rather than by class because the kind is what is persisted
 * in provisioning_jobs.kind, and a job queued today must still resolve years
 * later after the handler class has moved or been replaced by a different
 * platform's implementation.
 *
 * The registry is populated at boot by the application, not by this module: it
 * is the one seam through which provider-specific code enters a
 * provider-agnostic engine.
 */
interface HandlerRegistry
{
    /**
     * @param  ProvisioningHandler|class-string<ProvisioningHandler>  $handler  A class string is resolved from the container on first use, so
     *                                                                          registering a handler at boot never constructs an SDK client.
     */
    public function register(ProvisioningHandler|string $handler, ?ProvisioningJobKind $kind = null): void;

    /**
     * @throws HandlerNotRegisteredException
     */
    public function get(ProvisioningJobKind $kind): ProvisioningHandler;

    public function has(ProvisioningJobKind $kind): bool;

    /**
     * The kinds this deployment can currently do, for health checks and for
     * the error raised when one is missing.
     *
     * @return list<string>
     */
    public function kinds(): array;
}
