<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\DTOs;

use Lynomia\Modules\Compute\Domain\Enums\OsFamily;

/**
 * Everything a hypervisor needs to lay a fresh image onto a machine that
 * already exists — and, by what it does not carry, everything it must not
 * change.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately absent
 * ---------------------------------------------------------------------------
 *
 * There is no node, no numeric id, no MAC address and no network here. Those
 * are the machine's identity, and a reinstall is not allowed to touch them:
 * the id keeps the local row pointing at the right machine, the MAC keeps
 * every licence, DHCP reservation and firewall rule the customer built on it,
 * and the network is where the customer's address already routes. An adapter
 * that had to be *told* to keep them could be told not to.
 *
 * The disk size travels because the disk is being replaced and Proxmox needs
 * to be told how big to make the new one — but it is the size the machine
 * already has, read from its own row, not a size a caller may choose. A
 * reinstall that resizes is two operations wearing one name, and the billing
 * half of it would never happen.
 *
 * @immutable
 */
final readonly class ReinstallVmRequest
{
    /**
     * @param  string  $templateReference  The image the new disk is imported from.
     * @param  string  $storageName  The storage the replacement disk is created on — the one the machine is already using.
     * @param  int  $diskGib  The machine's existing disk size, restated because the new disk has to be created at some size.
     * @param  string  $hostname  The machine's existing hostname, rewritten into the guest by cloud-init.
     */
    public function __construct(
        public string $templateReference,
        public string $storageName,
        public int $diskGib,
        public string $hostname,
        public OsFamily $osFamily = OsFamily::Debian,
        public ?CloudInitConfig $cloudInit = null,
        /**
         * Whether to start the machine once the image is down.
         *
         * True by default and passed explicitly, because the alternative — a
         * customer who asked for a rebuild and gets a powered-off server —
         * looks exactly like a reinstall that failed.
         */
        public bool $startAfterInstall = true,
    ) {}
}
