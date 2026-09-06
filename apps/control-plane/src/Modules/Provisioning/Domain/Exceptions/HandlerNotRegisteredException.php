<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Exceptions;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Nothing is registered to do this kind of work.
 *
 * This is a deployment fault, not a provider fault: the engine was asked to do
 * something no handler was wired up for. It is classified permanent, because
 * retrying an unwired job every thirty seconds until the attempts run out only
 * delays the moment someone notices.
 */
final class HandlerNotRegisteredException extends DomainException
{
    private string $errorCode = 'provisioning.handler_not_registered';

    /**
     * @param  list<string>  $registered
     */
    public static function forKind(ProvisioningJobKind $kind, array $registered = []): self
    {
        $exception = new self(sprintf(
            'No provisioning handler is registered for "%s".',
            $kind->value,
        ));

        return $exception->withContext([
            'kind' => $kind->value,
            'registered' => implode(', ', $registered),
        ]);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 501;
    }
}
