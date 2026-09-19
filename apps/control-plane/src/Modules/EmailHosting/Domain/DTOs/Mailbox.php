<?php

declare(strict_types=1);

namespace Lynomia\Modules\EmailHosting\Domain\DTOs;

final readonly class Mailbox
{
    public function __construct(
        public string $address,
        public int $quotaBytes,
        public bool $suspended,
    ) {}
}
