<?php

declare(strict_types=1);

namespace Lynomia\Modules\EmailHosting\Domain\DTOs;

final readonly class MailDomain
{
    public function __construct(
        public string $domain,
        public bool $suspended,
        public int $mailboxCount,
    ) {}
}
