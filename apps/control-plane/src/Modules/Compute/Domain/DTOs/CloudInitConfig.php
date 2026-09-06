<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\DTOs;

/**
 * First-boot configuration handed to the guest.
 *
 * This is the only channel through which a customer ever gets access to a new
 * machine, so it is modelled explicitly rather than as a bag of provider
 * strings: an empty key list here means a server nobody can log into, and that
 * has to be visible at the type level rather than discovered by a customer.
 *
 * No password is carried. Key-only access is a platform decision, not a
 * provider one — a cloud-init password ends up in the hypervisor's config
 * file, its backups and its task log, none of which are ours to secure.
 *
 * @immutable
 */
final readonly class CloudInitConfig
{
    /**
     * @param  list<string>  $sshKeys  Authorised public keys, in OpenSSH single-line form.
     * @param  string|null  $ipConfig  Proxmox ipconfig0 syntax, e.g. "ip=192.0.2.10/24,gw=192.0.2.1".
     *                                 Null means DHCP, which is only correct on networks the
     *                                 platform runs a server on.
     * @param  list<string>  $nameservers
     */
    public function __construct(
        public string $user = 'lynomia',
        public array $sshKeys = [],
        public ?string $ipConfig = null,
        public array $nameservers = [],
        public ?string $searchDomain = null,
    ) {}

    public function hasKeys(): bool
    {
        return $this->sshKeys !== [];
    }

    /**
     * The keys as one newline-separated block, which is the shape every
     * cloud-init implementation expects before transport encoding.
     */
    public function sshKeyBlock(): string
    {
        return implode("\n", $this->sshKeys);
    }

    public function nameserverList(): ?string
    {
        return $this->nameservers === [] ? null : implode(' ', $this->nameservers);
    }
}
