<?php

declare(strict_types=1);

namespace Lynomia\Modules\ObjectStorage\Domain\DTOs;

/**
 * An access key as the store hands it out: the id, and the secret — once.
 *
 * The secret exists in this object for the length of the request that
 * created it and is shown to the customer exactly once. It is never
 * stored, never logged and never returned by any later read; a store that
 * can re-read a secret is a store that holds it in the clear. The object
 * redacts itself when dumped for the same reason.
 */
final readonly class IssuedAccessKey
{
    public function __construct(
        public string $accessKeyId,
        public string $secretAccessKey,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['accessKeyId' => $this->accessKeyId, 'secretAccessKey' => '[redacted]'];
    }
}
