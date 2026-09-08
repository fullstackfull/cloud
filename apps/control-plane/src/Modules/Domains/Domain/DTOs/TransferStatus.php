<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\DTOs;

use Carbon\CarbonImmutable;

/**
 * Where a transfer-in has got to at the registry.
 *
 * Transfers take days. The losing registrar has five of them to object at most
 * gTLD registries, and a customer watching a progress bar needs to be told
 * that rather than shown a spinner — so `pending` here is a first-class answer
 * and not a failure to have finished.
 *
 * @immutable
 */
final readonly class TransferStatus
{
    public function __construct(
        public string $name,
        /** pending | completed | rejected | cancelled */
        public string $state,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $providerReference = null,
        /**
         * What the registry said when it refused. Shown to the customer,
         * because the fixable reasons — the domain is locked, the auth code is
         * wrong, it was registered less than sixty days ago — are all things
         * only they can put right.
         */
        public ?string $reason = null,
    ) {}

    public function isPending(): bool
    {
        return $this->state === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->state === 'completed';
    }
}
