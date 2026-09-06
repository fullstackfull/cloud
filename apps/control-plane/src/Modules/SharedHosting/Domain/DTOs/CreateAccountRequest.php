<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

use SensitiveParameter;

/**
 * Everything a panel needs to open one account.
 *
 * The password is marked sensitive so that PHP itself keeps it out of stack
 * traces: a create that throws anywhere below this line would otherwise put
 * the customer's panel password into the error tracker, from which it cannot
 * be recalled. It is never logged, never persisted and never returned — the
 * platform's own record of the account holds no password at all, and a
 * customer who loses theirs gets a reset rather than a copy.
 *
 * The package is named rather than described. Quotas live on the panel's own
 * package definition, and a create that spelled out disk and bandwidth inline
 * would drift away from the package the customer is billed for the first time
 * anybody edits either.
 *
 * @immutable
 */
final readonly class CreateAccountRequest
{
    /**
     * @param  string  $username  The panel account name. Already validated and truncated to the
     *                            panel's limit by the caller — a name the panel silently shortens
     *                            is how two customers end up sharing one account.
     * @param  string  $packageName  hosting_packages.panel_package_name: the package as the PANEL
     *                               knows it, not the platform's slug.
     * @param  bool  $dedicatedIp  Requesting one on a node that has none available is a create
     *                             failure at cPanel rather than a silent fall back to shared.
     * @param  array<string, scalar|null>  $extra  Panel-specific parameters the caller has decided are safe.
     */
    public function __construct(
        public string $username,
        public string $primaryDomain,
        #[SensitiveParameter]
        public string $password,
        public string $packageName,
        public string $contactEmail,
        public bool $dedicatedIp = false,
        public ?string $ipAddress = null,
        public ?string $locale = null,
        public array $extra = [],
    ) {}
}
