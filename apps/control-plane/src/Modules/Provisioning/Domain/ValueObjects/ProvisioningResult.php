<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\ValueObjects;

use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;

/**
 * What a handler reports back about one attempt.
 *
 * A failure is a value here, not an exception, for the same reason a declined
 * card is a value in the payments module: the engine has to distinguish "the
 * provider said no" from "the handler itself broke", and those two need
 * opposite retry behaviour. A handler that throws is still handled — see
 * RunProvisioningJob — but a handler that knows why it failed is expected to
 * say so, because only it can classify the failure correctly.
 *
 * The remote job id is carried even on failure. A provider that accepted the
 * work and then timed out has given us the single most valuable fact in the
 * module: the identifier under which the resource may exist.
 *
 * @immutable
 */
final readonly class ProvisioningResult
{
    /**
     * @param  array<string, mixed>  $metadata  The provider's own response, redacted before it is stored.
     */
    private function __construct(
        public bool $successful,
        public ?string $remoteJobId,
        public ?string $providerReference,
        public ?FailureClass $failureClass,
        public ?string $errorCode,
        public ?string $errorMessage,
        public array $metadata,
    ) {}

    /**
     * @param  string|null  $providerReference  The resource itself — a Proxmox VMID, a cPanel account name — as
     *                                          opposed to the id of the job that created it.
     * @param  array<string, mixed>  $metadata
     */
    public static function succeeded(
        ?string $remoteJobId = null,
        ?string $providerReference = null,
        array $metadata = [],
    ): self {
        return new self(true, $remoteJobId, $providerReference, null, null, null, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function failed(
        FailureClass $failureClass,
        string $errorCode,
        string $errorMessage,
        ?string $remoteJobId = null,
        ?string $providerReference = null,
        array $metadata = [],
    ): self {
        return new self(false, $remoteJobId, $providerReference, $failureClass, $errorCode, $errorMessage, $metadata);
    }

    public function isFailure(): bool
    {
        return ! $this->successful;
    }
}
