<?php

declare(strict_types=1);

namespace Lynomia\Modules\EmailHosting\Domain\DTOs;

final readonly class MailboxUsage
{
    public function __construct(
        public string $address,
        public int $bytesUsed,
        public int $quotaBytes,
    ) {}
}
