<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

/**
 * What the panel says it created.
 *
 * The username is the provider reference for a hosting account: unlike a VM
 * there is no numeric id, and the panel's account name is the only handle
 * every subsequent call — suspend, terminate, usage, SSO — is made against.
 * It is echoed back from the panel's own answer rather than assumed from the
 * request, because a panel that shortened or altered the name has just made
 * the platform's copy wrong, and every later call would address an account
 * that does not exist.
 *
 * @immutable
 */
final readonly class HostingAccountResult
{
    /**
     * @param  string|null  $ipAddress  The address the account was actually given, which is not
     *                                  necessarily the one that was asked for.
     * @param  array<string, mixed>  $metadata  The panel's own answer, redacted.
     */
    public function __construct(
        public string $username,
        public string $primaryDomain,
        public string $packageName,
        public ?string $ipAddress = null,
        public ?string $nameserver = null,
        public array $metadata = [],
    ) {}
}
