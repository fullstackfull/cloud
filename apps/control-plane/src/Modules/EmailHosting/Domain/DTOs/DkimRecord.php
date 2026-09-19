<?php

declare(strict_types=1);

namespace Lynomia\Modules\EmailHosting\Domain\DTOs;

/**
 * The DKIM public key the mail platform signs with, as the TXT record the
 * customer's zone has to carry. Only the public half ever leaves the mail
 * platform; the private key is its own and is not on this interface.
 */
final readonly class DkimRecord
{
    public function __construct(
        public string $domain,
        public string $selector,
        public string $txtValue,
    ) {}
}
